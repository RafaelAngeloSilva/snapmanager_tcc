<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SelectGalleryImagesRequest;
use App\Http\Resources\PublicGalleryResource;
use App\Models\Gallery;
use App\Models\GalleryImage;
use App\Services\GalleryThumbnailService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PublicGalleryController extends Controller
{
    private const DISK = 'local';

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

        // O público sempre vê a versão com marca d'água quando ela existe
        // (já limitada a 2200 px). Sem marca d'água, serve uma cópia ampliada
        // leve (máx. 2000 px) em vez do original de vários MB.
        $servedPath = $galleryImage->watermarked_path
            ?: ($thumbnails->ensurePreview($galleryImage) ?: $galleryImage->path);

        abort_unless(Storage::disk(self::DISK)->exists($servedPath), 404);

        return Storage::disk(self::DISK)->response($servedPath, $galleryImage->original_name, [
            'Content-Type' => $galleryImage->mime_type,
            // Arquivo imutável (nome UUID por upload): o navegador reaproveita
            // a cópia em cache em vez de repetir a requisição ao PHP toda vez
            // que a grade de fotos é montada.
            'Cache-Control' => 'private, max-age=3600',
        ]);
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

        // Gera a miniatura na hora se ela ainda não existir (fotos antigas),
        // em vez de mandar o arquivo cheio de vários MB para uma célula da grade.
        // Com marca d'água, a miniatura sai da cópia COM marca — o original
        // sem marca nunca é exposto aqui.
        $servedPath = $galleryImage->watermarked_path
            ? ($thumbnails->ensureWatermarkedThumbnail($galleryImage) ?: $galleryImage->watermarked_path)
            : ($thumbnails->ensureThumbnail($galleryImage) ?: $galleryImage->path);

        abort_unless(Storage::disk(self::DISK)->exists($servedPath), 404);

        return Storage::disk(self::DISK)->response($servedPath, $galleryImage->original_name, [
            'Content-Type' => $galleryImage->mime_type,
            // Arquivo imutável (nome UUID por upload): o navegador reaproveita
            // a cópia em cache em vez de repetir a requisição ao PHP toda vez
            // que a grade de fotos é montada.
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }
}
