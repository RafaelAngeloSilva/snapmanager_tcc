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
use App\Services\GalleryThumbnailService;
use App\Services\WatermarkService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class GalleryController extends Controller
{
    private const DISK = 'local';

    /**
     * Lado maior da miniatura, em pixels — suficiente pra uma célula de
     * grade em tela retina (a grade renderiza bem menor que isso via CSS),
     * bem abaixo do que um original de até 10MB costuma ter.
     */
    private const THUMBNAIL_MAX_DIMENSION = 480;

    public function __construct(private readonly WatermarkService $watermarkService) {}

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

        // 1) Processamento pesado (gravar arquivo, marca d'água, miniaturas)
        //    FORA da transação. Antes tudo isso rodava com a transação aberta:
        //    a conexão com o banco ficava presa por vários segundos por foto,
        //    segurando locks enquanto o GD redimensionava imagens.
        $prepared = [];

        try {
            foreach ($request->file('images', []) as $file) {
                $extension = $file->extension() ?: $file->getClientOriginalExtension();
                $storedName = Str::uuid()->toString().'.'.$extension;
                $path = $file->storeAs("galleries/{$gallery->id}", $storedName, self::DISK);

                [$width, $height] = @getimagesize($file->getRealPath()) ?: [null, null];

                $watermarkedPath = $shouldWatermark
                    ? $this->generateWatermarkedCopy($gallery, $path, $storedName)
                    : null;

                // Miniatura sem marca (painel interno) sempre é gerada;
                // miniatura com marca (galeria pública) só faz sentido
                // quando a galeria usa marca d'água — nesse caso é gerada a
                // partir da cópia já watermarked (mais barato que aplicar a
                // marca de novo numa imagem pequena).
                $thumbnailPath = $this->generateThumbnail($path, $storedName, $gallery->id, 'thumbnails');
                $watermarkedThumbnailPath = $watermarkedPath
                    ? $this->generateThumbnail($watermarkedPath, $storedName, $gallery->id, 'watermarked-thumbnails')
                    : null;

                $prepared[] = [
                    'original_name' => $file->getClientOriginalName(),
                    'stored_name' => $storedName,
                    'path' => $path,
                    'watermarked_path' => $watermarkedPath,
                    'thumbnail_path' => $thumbnailPath,
                    'watermarked_thumbnail_path' => $watermarkedThumbnailPath,
                    'mime_type' => $file->getMimeType(),
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
        } catch (\Throwable $e) {
            // Nada foi salvo no banco: remove os arquivos já gravados para não
            // deixar órfãos no disco.
            Storage::disk(self::DISK)->delete(collect($prepared)
                ->flatMap(fn ($data) => [$data['path'], $data['watermarked_path'], $data['thumbnail_path'], $data['watermarked_thumbnail_path']])
                ->filter()
                ->all());

            throw $e;
        }

        return GalleryImageResource::collection(collect($images));
    }

    private function generateWatermarkedCopy(Gallery $gallery, string $originalPath, string $storedName): ?string
    {
        $sourceFullPath = Storage::disk(self::DISK)->path($originalPath);
        $watermarkedRelativePath = "galleries/{$gallery->id}/watermarked/{$storedName}";
        $destFullPath = Storage::disk(self::DISK)->path($watermarkedRelativePath);

        $success = match ($gallery->watermark_type) {
            WatermarkType::Text => $this->watermarkService->applyTextWatermark($sourceFullPath, $destFullPath, $gallery->watermark_text),
            WatermarkType::Image => $gallery->watermark_image_path
                ? $this->watermarkService->applyImageWatermark(
                    $sourceFullPath,
                    $destFullPath,
                    Storage::disk(self::DISK)->path($gallery->watermark_image_path)
                )
                : false,
            default => false,
        };

        return $success ? $watermarkedRelativePath : null;
    }

    private function generateThumbnail(string $sourcePath, string $storedName, int $galleryId, string $subfolder): ?string
    {
        $sourceFullPath = Storage::disk(self::DISK)->path($sourcePath);
        $relativePath = "galleries/{$galleryId}/{$subfolder}/{$storedName}";
        $destFullPath = Storage::disk(self::DISK)->path($relativePath);

        return $this->watermarkService->resizeCopy($sourceFullPath, $destFullPath, self::THUMBNAIL_MAX_DIMENSION)
            ? $relativePath
            : null;
    }

    public function destroyImage(Gallery $gallery, GalleryImage $image): JsonResponse
    {
        abort_unless($image->gallery_id === $gallery->id, 404);

        Storage::disk(self::DISK)->delete(array_filter([
            $image->path,
            $image->watermarked_path,
            $image->thumbnail_path,
            $image->watermarked_thumbnail_path,
            // Cópia ampliada leve da galeria pública (GalleryThumbnailService::ensurePreview).
            $image->stored_name ? "galleries/{$gallery->id}/previews/{$image->stored_name}" : null,
        ]));
        $image->delete();

        return response()->json(null, 204);
    }

    
    public function uploadWatermarkImage(StoreGalleryWatermarkImageRequest $request, Gallery $gallery): JsonResponse
    {
        $file = $request->file('watermark_image');
        $extension = $file->extension() ?: $file->getClientOriginalExtension();
        $storedName = Str::uuid()->toString().'.'.$extension;
        $path = $file->storeAs("galleries/{$gallery->id}/watermark-source", $storedName, self::DISK);

        if ($gallery->watermark_image_path) {
            Storage::disk(self::DISK)->delete($gallery->watermark_image_path);
        }

        $gallery->update(['watermark_image_path' => $path]);

        return response()->json([
            'message' => 'Imagem de marca d\'água enviada com sucesso.',
            'data' => GalleryResource::make($gallery->load(['client', 'order'])),
        ]);
    }

    public function showImageFile(Gallery $gallery, GalleryImage $image): StreamedResponse
    {
        abort_unless($image->gallery_id === $gallery->id, 404);
        abort_unless(Storage::disk(self::DISK)->exists($image->path), 404);

        return Storage::disk(self::DISK)->response($image->path, $image->original_name, [
            'Content-Type' => $image->mime_type,
            // Arquivo imutável (nome UUID por upload): o navegador reaproveita
            // a cópia em cache em vez de repetir a requisição ao PHP toda vez
            // que a grade de fotos é montada.
            'Cache-Control' => 'private, max-age=86400',
        ]);
    }

    /**
     * Miniatura sem marca d'água (painel interno é sempre "a foto original,
     * sem marca" — aqui só menor). Fotos enviadas antes da miniatura existir
     * caem de volta pro arquivo original, sem quebrar a grade.
     */
    public function showThumbnailFile(Gallery $gallery, GalleryImage $image, GalleryThumbnailService $thumbnails): StreamedResponse
    {
        abort_unless($image->gallery_id === $gallery->id, 404);

        // Fotos sem miniatura (enviadas antes do recurso existir) ganham uma
        // agora, no primeiro acesso — só cai no original se a geração falhar.
        $servedPath = $thumbnails->ensureThumbnail($image) ?: $image->path;

        abort_unless(Storage::disk(self::DISK)->exists($servedPath), 404);

        return Storage::disk(self::DISK)->response($servedPath, $image->original_name, [
            'Content-Type' => $image->mime_type,
            // Arquivo imutável (nome UUID por upload): o navegador reaproveita
            // a cópia em cache em vez de repetir a requisição ao PHP toda vez
            // que a grade de fotos é montada.
            'Cache-Control' => 'private, max-age=86400',
        ]);
    }

    
    public function showWatermarkImageFile(Gallery $gallery): StreamedResponse
    {
        abort_unless($gallery->watermark_image_path, 404);
        abort_unless(Storage::disk(self::DISK)->exists($gallery->watermark_image_path), 404);

        return Storage::disk(self::DISK)->response($gallery->watermark_image_path);
    }
}
