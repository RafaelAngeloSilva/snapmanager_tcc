<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreGalleryImagesRequest;
use App\Http\Requests\StoreGalleryRequest;
use App\Http\Requests\StoreGalleryWatermarkImageRequest;
use App\Http\Requests\UpdateGalleryRequest;
use App\Enums\GalleryStatus;
use App\Enums\WatermarkType;
use App\Http\Resources\GalleryImageResource;
use App\Http\Resources\GalleryResource;
use App\Models\Gallery;
use App\Models\GalleryImage;
use App\Exceptions\StoredFileNotFoundException;
use App\Services\FileStorage;
use App\Services\GalleryThumbnailService;
use App\Services\WatermarkService;
use App\Support\ExecutionTimeBudget;
use App\Support\FileKeys;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Galerias (lado interno, equipe). Todos os arquivos passam pelo
 * FileStorage — disco local no desenvolvimento, bucket S3 privado em
 * produção — e são servidos pelo Laravel depois das regras de acesso.
 */
class GalleryController extends Controller
{
    /** Cache no navegador: arquivos imutáveis (UUID por upload). */
    private const PRIVATE_CACHE = 'private, max-age=86400';

    /**
     * Lado maior da miniatura, em pixels — suficiente pra uma célula de
     * grade em tela retina (a grade renderiza bem menor que isso via CSS),
     * bem abaixo do que um original de até 10MB costuma ter.
     */
    private const THUMBNAIL_MAX_DIMENSION = 480;

    /**
     * Tempo máximo para processar UMA foto (gravar o original no S3, gerar
     * marca d'água e miniaturas e gravá-las). Renovado a cada foto — ver
     * ExecutionTimeBudget. O front-end já envia uma foto por requisição;
     * isto protege também lotes enviados de uma vez pela API.
     */
    private const SECONDS_PER_PHOTO = 120;

    public function __construct(
        private readonly WatermarkService $watermarkService,
        private readonly FileStorage $files,
        private readonly ExecutionTimeBudget $timeBudget,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $galleries = Gallery::query()
            ->with(['client', 'order'])
            ->withCount([
                'images',
                'images as selected_images_count' => fn ($query) => $query->where('is_selected', true),
            ])
            ->when($request->filled('client_id'), fn ($query) => $query->where('client_id', $request->input('client_id')))
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->input('status')))
            ->orderByDesc('created_at')
            ->paginate(15)
            ->withQueryString();

        return GalleryResource::collection($galleries);
    }

    public function store(StoreGalleryRequest $request): JsonResponse
    {
        $gallery = Gallery::create([
            ...$this->withWatermarkDefaults($request->validated()),
            'user_id' => $request->user()->id,
        ]);
        
        $gallery->refresh();

        $gallery->load(['client', 'order']);

        return GalleryResource::make($gallery)
            ->response()
            ->setStatusCode(201);
    }

    
    private function withWatermarkDefaults(array $data): array
    {
        if (($data['watermark_type'] ?? 'text') === 'text' && blank($data['watermark_text'] ?? null)) {
            $data['watermark_text'] = Gallery::DEFAULT_WATERMARK_TEXT;
        }

        return $data;
    }

    public function show(Gallery $gallery): GalleryResource
    {
        $gallery->load(['client', 'order', 'user', 'images' => fn ($query) => $query->orderBy('sort_order')->orderBy('id')]);
        GalleryImage::assignDisplayNumbers($gallery->images);

        return GalleryResource::make($gallery);
    }

    public function update(UpdateGalleryRequest $request, Gallery $gallery): GalleryResource
    {
        $gallery->update($this->withWatermarkDefaults($request->validated()));

        return GalleryResource::make($gallery->load(['client', 'order']));
    }

    public function destroy(Gallery $gallery): JsonResponse
    {
        $gallery->delete();

        return response()->json(null, 204);
    }

    public function close(Gallery $gallery): JsonResponse
    {
        $gallery->update([
            'status' => GalleryStatus::Closed,
            'closed_at' => now(),
        ]);

        return response()->json([
            'message' => 'Galeria encerrada com sucesso.',
            'data' => GalleryResource::make($gallery->load(['client', 'order'])),
        ]);
    }

    public function uploadImages(StoreGalleryImagesRequest $request, Gallery $gallery): AnonymousResourceCollection
    {
        $shouldWatermark = $gallery->shouldWatermark();

        // A imagem de marca d'água (logo) é lida UMA vez por upload, não uma
        // vez por foto (no S3 seriam N downloads iguais).
        $watermarkImage = $shouldWatermark ? $this->watermarkImageBytes($gallery) : null;

        // Processamento pesado (marca d'água, miniaturas, envio ao
        // armazenamento) FORA da transação — a conexão com o banco não fica
        // presa enquanto o GD processa. Tudo sai do arquivo temporário do
        // upload (PHP) e só os RESULTADOS vão para o armazenamento: nada é
        // baixado do S3 durante o upload e nada fica no disco do servidor.
        $prepared = [];
        $written = [];
        $committed = false;

        // Se a requisição MORRER no meio (ex.: tempo de execução esgotado —
        // erro fatal, que não passa pelo catch abaixo), os objetos já
        // gravados no armazenamento seriam órfãos: o banco não chegou a
        // registrar nada. A limpeza roda no encerramento do PHP.
        register_shutdown_function(function () use (&$written, &$committed, $gallery) {
            if ($committed || $written === []) {
                return;
            }

            $this->timeBudget->renew(30);
            $this->files->delete($written);

            Log::warning('Upload de fotos interrompido: objetos já gravados foram removidos.', [
                'gallery_id' => $gallery->id,
                'objects_removed' => count($written),
            ]);
        });

        try {
            foreach ($request->file('images', []) as $file) {
                // Cada foto com o próprio orçamento de tempo (o limite do PHP
                // não é mais dividido entre todas as fotos do lote).
                $this->timeBudget->renew(self::SECONDS_PER_PHOTO);

                $extension = strtolower($file->extension() ?: $file->getClientOriginalExtension());
                $storedName = FileKeys::newName($extension);
                $mimeType = $file->getMimeType();

                [$width, $height] = @getimagesize($file->getRealPath()) ?: [null, null];

                // 1) Todas as versões com UMA decodificação do original
                //    (miniatura sem marca para o painel; versão e miniatura
                //    com marca d'água para a galeria pública).
                $variants = $this->watermarkService->galleryVariants(
                    $file->get(),
                    $extension,
                    self::THUMBNAIL_MAX_DIMENSION,
                    $shouldWatermark && $gallery->watermark_type === WatermarkType::Text ? $gallery->watermark_text : null,
                    $shouldWatermark && $gallery->watermark_type === WatermarkType::Image ? $watermarkImage : null,
                );

                // 2) Original + versões geradas vão ao armazenamento juntos —
                //    em paralelo no S3 (FileStorage::putMany). As keys entram
                //    em $written ANTES do envio: se algo falhar no meio, a
                //    limpeza também alcança as que chegaram a ser gravadas.
                $path = FileKeys::gallery($gallery->id, FileKeys::GALLERY_ORIGINALS, $storedName);
                $keys = ['path' => $path];
                $writes = [['key' => $path, 'contents' => $file, 'mime' => $mimeType]];

                foreach ([
                    'watermarked_path' => [FileKeys::GALLERY_WATERMARKED, 'watermarked'],
                    'thumbnail_path' => [FileKeys::GALLERY_THUMBNAILS, 'thumbnail'],
                    'watermarked_thumbnail_path' => [FileKeys::GALLERY_WATERMARKED_THUMBNAILS, 'watermarked_thumbnail'],
                ] as $column => [$variant, $version]) {
                    $keys[$column] = null;

                    if ($variants[$version] !== null) {
                        $keys[$column] = FileKeys::gallery($gallery->id, $variant, $storedName);
                        $writes[] = ['key' => $keys[$column], 'contents' => $variants[$version], 'mime' => $mimeType];
                    }
                }

                unset($variants);
                array_push($written, ...array_column($writes, 'key'));
                $this->files->putMany($writes);
                unset($writes);

                $prepared[] = [
                    'original_name' => $file->getClientOriginalName(),
                    'stored_name' => $storedName,
                    ...$keys,
                    'mime_type' => $mimeType,
                    'size' => $file->getSize(),
                    'width' => $width,
                    'height' => $height,
                ];
            }

            // 2) Só os INSERTs dentro da transação — rápida.
            $images = DB::transaction(function () use ($gallery, $prepared) {
                $created = [];
                $nextSortOrder = $gallery->images()->max('sort_order') + 1;

                foreach ($prepared as $data) {
                    $created[] = $gallery->images()->create([
                        ...$data,
                        'sort_order' => $nextSortOrder++,
                        'is_selected' => false,
                    ]);
                }

                return $created;
            });

            $committed = true;
        } catch (\Throwable $e) {
            // Nada foi salvo no banco: remove os objetos já gravados para não
            // deixar órfãos no armazenamento.
            $this->files->delete($written);
            $written = [];

            throw $e;
        }

        return GalleryImageResource::collection(collect($images));
    }

    private function watermarkImageBytes(Gallery $gallery): ?string
    {
        if ($gallery->watermark_type !== WatermarkType::Image || ! $gallery->watermark_image_path) {
            return null;
        }

        try {
            return $this->files->get($gallery->watermark_image_path);
        } catch (StoredFileNotFoundException) {
            // Mesmo comportamento de antes: sem a imagem, a foto fica sem
            // versão com marca (a galeria pública cai no fluxo sem marca).
            return null;
        }
    }

    /**
     * Exclusão definitiva de UMA foto (ação explícita do admin): remove o
     * registro e todas as versões no armazenamento — original, miniaturas,
     * marca d'água e prévia. Excluir a GALERIA inteira continua sendo soft
     * delete e não apaga arquivos (regra existente).
     */
    public function destroyImage(Gallery $gallery, GalleryImage $image): JsonResponse
    {
        abort_unless($image->gallery_id === $gallery->id, 404);

        $this->files->delete([
            $image->path,
            $image->watermarked_path,
            $image->thumbnail_path,
            $image->watermarked_thumbnail_path,
            // Prévia leve da galeria pública (GalleryThumbnailService::previewKey).
            $image->stored_name ? FileKeys::gallery($gallery->id, FileKeys::GALLERY_PREVIEWS, $image->stored_name) : null,
        ]);
        $image->delete();

        return response()->json(null, 204);
    }

    public function uploadWatermarkImage(StoreGalleryWatermarkImageRequest $request, Gallery $gallery): JsonResponse
    {
        /** @var UploadedFile $file */
        $file = $request->file('watermark_image');
        $extension = strtolower($file->extension() ?: $file->getClientOriginalExtension());
        $path = FileKeys::gallery($gallery->id, FileKeys::GALLERY_WATERMARK_SOURCE, FileKeys::newName($extension));

        $this->files->putFile($path, $file, $file->getMimeType());

        $previous = $gallery->watermark_image_path;
        $gallery->update(['watermark_image_path' => $path]);
        $this->files->delete($previous);

        return response()->json([
            'message' => 'Imagem de marca d\'água enviada com sucesso.',
            'data' => GalleryResource::make($gallery->load(['client', 'order'])),
        ]);
    }

    /** Original (sem marca) — só para a equipe autenticada; nunca vai para a galeria pública. */
    public function showImageFile(Gallery $gallery, GalleryImage $image): StreamedResponse
    {
        abort_unless($image->gallery_id === $gallery->id, 404);

        return $this->files->response($image->path, $image->original_name, $image->mime_type, [
            'Cache-Control' => self::PRIVATE_CACHE,
        ]);
    }

    /**
     * Miniatura sem marca d'água (painel interno é sempre "a foto original,
     * sem marca" — aqui só menor). Fotos antigas sem miniatura ganham uma no
     * primeiro acesso; se a geração falhar, cai no original.
     */
    public function showThumbnailFile(Gallery $gallery, GalleryImage $image, GalleryThumbnailService $thumbnails): StreamedResponse
    {
        abort_unless($image->gallery_id === $gallery->id, 404);

        $headers = ['Cache-Control' => self::PRIVATE_CACHE];
        $key = $thumbnails->ensureThumbnail($image) ?: $image->path;

        try {
            return $this->files->response($key, $image->original_name, $image->mime_type, $headers);
        } catch (StoredFileNotFoundException $e) {
            // A key do banco aponta para um objeto que não existe mais:
            // gera de novo uma vez (ou cai no original).
            if ($key === $image->path) {
                throw $e;
            }

            $key = $thumbnails->regenerate($image, watermarked: false) ?: $image->path;

            return $this->files->response($key, $image->original_name, $image->mime_type, $headers);
        }
    }

    public function showWatermarkImageFile(Gallery $gallery): StreamedResponse
    {
        abort_unless($gallery->watermark_image_path, 404);

        return $this->files->response($gallery->watermark_image_path, 'marca-dagua.'.pathinfo($gallery->watermark_image_path, PATHINFO_EXTENSION), headers: [
            'Cache-Control' => self::PRIVATE_CACHE,
        ]);
    }
}
