<?php

namespace App\Services;

use App\Exceptions\StoredFileNotFoundException;
use App\Models\GalleryImage;
use App\Support\FileKeys;

/**
 * Versões derivadas das fotos: miniatura (grade interna), miniatura com
 * marca (galeria pública) e prévia ampliada leve (lightbox público quando a
 * galeria não usa marca d'água).
 *
 * Tudo pelo FileStorage — funciona com disco local ou S3.
 *
 * Custo no S3: quando a key da miniatura já está no banco, ela é usada
 * direto (nenhum exists()/HEAD antes). O original só é baixado quando a
 * miniatura precisa ser GERADA (fotos antigas, sem miniatura) — uma vez por
 * foto; depois disso a grade nunca mais toca no original. Rode
 * `php artisan galleries:thumbnails` antes de migrar para o S3 para que
 * isso nem aconteça em produção.
 */
class GalleryThumbnailService
{
    /** Mesmo tamanho usado no upload (GalleryController::THUMBNAIL_MAX_DIMENSION). */
    public const MAX_DIMENSION = 480;

    /** Lado maior da versão "ampliada" (lightbox) quando não há marca d'água. */
    public const PREVIEW_MAX_DIMENSION = 2000;

    public function __construct(
        private readonly WatermarkService $watermarkService,
        private readonly FileStorage $files,
    ) {}

    /** Key da miniatura sem marca; gera se ainda não existir. Null = não foi possível gerar. */
    public function ensureThumbnail(GalleryImage $image): ?string
    {
        return $image->thumbnail_path ?: $this->regenerate($image, watermarked: false);
    }

    /** Key da miniatura com marca d'água; null se a foto não tem versão com marca. */
    public function ensureWatermarkedThumbnail(GalleryImage $image): ?string
    {
        if (! $image->watermarked_path) {
            return null;
        }

        return $image->watermarked_thumbnail_path ?: $this->regenerate($image, watermarked: true);
    }

    /**
     * (Re)gera a miniatura a partir da fonte — usado também quando a key
     * gravada no banco aponta para um objeto que sumiu do armazenamento.
     */
    public function regenerate(GalleryImage $image, bool $watermarked): ?string
    {
        $source = $watermarked ? $image->watermarked_path : $image->path;
        $variant = $watermarked ? FileKeys::GALLERY_WATERMARKED_THUMBNAILS : FileKeys::GALLERY_THUMBNAILS;
        $column = $watermarked ? 'watermarked_thumbnail_path' : 'thumbnail_path';

        $key = $this->generate($image, $source, $variant, self::MAX_DIMENSION);

        if ($key === null) {
            return null;
        }

        // saveQuietly: sem disparar eventos/observers nem mexer em updated_at
        // da foto por causa de um detalhe interno de armazenamento.
        $image->timestamps = false;
        $image->forceFill([$column => $key])->saveQuietly();
        $image->timestamps = true;

        return $key;
    }

    /**
     * Key derivada da prévia ampliada (sem coluna no banco). Quem serve tenta
     * esta key primeiro e só chama generatePreview() se ela não existir.
     */
    public function previewKey(GalleryImage $image): string
    {
        return FileKeys::gallery($image->gallery_id, FileKeys::GALLERY_PREVIEWS, $this->fileName($image));
    }

    /** Foto já pequena: serve o próprio arquivo, não vale gerar cópia. */
    public function needsPreview(GalleryImage $image): bool
    {
        return ! ($image->width && $image->height && max($image->width, $image->height) <= self::PREVIEW_MAX_DIMENSION);
    }

    /** Gera a prévia ampliada leve (máx. 2000 px) a partir do original. */
    public function generatePreview(GalleryImage $image): ?string
    {
        return $this->generate($image, $image->path, FileKeys::GALLERY_PREVIEWS, self::PREVIEW_MAX_DIMENSION);
    }

    private function generate(GalleryImage $image, ?string $sourceKey, string $variant, int $maxDimension): ?string
    {
        if (! $sourceKey) {
            return null;
        }

        try {
            $source = $this->files->get($sourceKey);
        } catch (StoredFileNotFoundException) {
            return null;
        }

        $fileName = $this->fileName($image);
        $resized = $this->watermarkService->resized($source, $maxDimension, pathinfo($fileName, PATHINFO_EXTENSION));

        if ($resized === null) {
            return null;
        }

        $key = FileKeys::gallery($image->gallery_id, $variant, $fileName);
        $this->files->put($key, $resized, $image->mime_type);

        return $key;
    }

    private function fileName(GalleryImage $image): string
    {
        return $image->stored_name ?: basename((string) $image->path);
    }
}
