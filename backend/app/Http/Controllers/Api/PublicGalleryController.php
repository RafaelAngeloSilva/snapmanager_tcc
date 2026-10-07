<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SelectGalleryImagesRequest;
use App\Http\Resources\PublicGalleryResource;
use App\Models\Gallery;
use App\Models\GalleryImage;
use App\Exceptions\StoredFileNotFoundException;
use App\Services\FileStorage;
use App\Services\GalleryThumbnailService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Galeria pública (link por token). Os arquivos vêm do FileStorage (local
 * ou S3) e são servidos pelo Laravel — o cliente nunca recebe URL do S3.
 * Com marca d'água, o ORIGINAL nunca é servido por aqui.
 */
class PublicGalleryController extends Controller
{
    /** Arquivos imutáveis (UUID por upload): o navegador reaproveita a cópia. */
    private const PUBLIC_CACHE = 'private, max-age=3600';

    public function __construct(private readonly FileStorage $files) {}

    public function show(Gallery $gallery): PublicGalleryResource
    {
        $gallery->load(['images' => fn ($query) => $query->orderBy('sort_order')->orderBy('id')]);
        GalleryImage::assignDisplayNumbers($gallery->images);

        return PublicGalleryResource::make($gallery);
    }

    /**
     * The client can keep changing their selection while the gallery is
     * active (no "locked after first confirmation" rule) — every successful
     * confirmation, including a later change, moves selection_completed_at
     * forward to "now". Blocked entirely once the gallery is expired/closed,
     * via the isAccessible() check below.
     */
    public function selectImages(SelectGalleryImagesRequest $request, Gallery $gallery): JsonResponse
    {
        if (! $gallery->isAccessible()) {
            return response()->json([
                'message' => 'Esta galeria não está mais disponível para seleção de fotos.',
            ], 409);
        }

        $selectedIds = $request->validated('image_ids', []);

        DB::transaction(function () use ($gallery, $selectedIds) {
            $gallery->images()->update(['is_selected' => false, 'selected_at' => null]);

            if (! empty($selectedIds)) {
                $gallery->images()
                    ->whereIn('id', $selectedIds)
                    ->update(['is_selected' => true, 'selected_at' => now()]);
            }

            $gallery->update(['selection_completed_at' => now()]);
        });

        $gallery->load(['images' => fn ($query) => $query->orderBy('sort_order')->orderBy('id')]);
        GalleryImage::assignDisplayNumbers($gallery->images);

        return response()->json([
            'message' => 'Seleção enviada com sucesso.',
            'data' => PublicGalleryResource::make($gallery),
        ]);
    }

    public function showImageFile(Gallery $gallery, int $image, GalleryThumbnailService $thumbnails): StreamedResponse
    {
        $galleryImage = $gallery->images()->findOrFail($image);
        $headers = ['Cache-Control' => self::PUBLIC_CACHE];

        // O público sempre vê a versão com marca d'água quando ela existe
        // (já limitada a 2200 px).
        if ($galleryImage->watermarked_path) {
            return $this->files->response($galleryImage->watermarked_path, $galleryImage->original_name, $galleryImage->mime_type, $headers);
        }

        // Galeria sem marca d'água: prévia leve (máx. 2000 px) em vez do
        // original de vários MB. Tenta a prévia direto (uma leitura só) e
        // só gera se ela ainda não existir.
        if ($thumbnails->needsPreview($galleryImage)) {
            try {
                return $this->files->response($thumbnails->previewKey($galleryImage), $galleryImage->original_name, $galleryImage->mime_type, $headers);
            } catch (StoredFileNotFoundException) {
                if ($preview = $thumbnails->generatePreview($galleryImage)) {
                    return $this->files->response($preview, $galleryImage->original_name, $galleryImage->mime_type, $headers);
                }
            }
        }

        return $this->files->response($galleryImage->path, $galleryImage->original_name, $galleryImage->mime_type, $headers);
    }

    /**
     * Miniatura pra grade da galeria pública — mesma regra de proteção do
     * arquivo cheio (nunca serve o original sem marca quando a galeria usa
     * marca d'água), só que num arquivo bem menor. O lightbox (foto
     * ampliada) continua usando showImageFile() acima, não esta rota.
     */
    public function showThumbnailFile(Gallery $gallery, int $image, GalleryThumbnailService $thumbnails): StreamedResponse
    {
        $galleryImage = $gallery->images()->findOrFail($image);
        $headers = ['Cache-Control' => self::PUBLIC_CACHE];
        $watermarked = (bool) $galleryImage->watermarked_path;

        // Fallback se a miniatura não puder ser gerada: a versão cheia COM
        // marca (ou o original, quando a galeria não usa marca d'água).
        $fallback = $watermarked ? $galleryImage->watermarked_path : $galleryImage->path;
        $key = ($watermarked
            ? $thumbnails->ensureWatermarkedThumbnail($galleryImage)
            : $thumbnails->ensureThumbnail($galleryImage)) ?: $fallback;

        try {
            return $this->files->response($key, $galleryImage->original_name, $galleryImage->mime_type, $headers);
        } catch (StoredFileNotFoundException $e) {
            if ($key === $fallback) {
                throw $e;
            }

            $key = $thumbnails->regenerate($galleryImage, $watermarked) ?: $fallback;

            return $this->files->response($key, $galleryImage->original_name, $galleryImage->mime_type, $headers);
        }
    }
}
