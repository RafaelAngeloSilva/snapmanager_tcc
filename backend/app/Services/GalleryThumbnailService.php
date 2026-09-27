<?php

namespace App\Services;

use App\Models\GalleryImage;
use Illuminate\Support\Facades\Storage;

/**
 * Garante que cada foto tenha miniatura (grade interna) e, quando a galeria usa
 * marca d'água, miniatura com marca (galeria pública).
 *
 * Por que existe: fotos enviadas antes da miniatura ser implementada (ou cujo
 * upload falhou ao gerá-la) ficavam com thumbnail_path nulo, e a grade caía no
 * ORIGINAL — arquivos de 2 a 9 MB e 4000+ px. O navegador precisava baixar e
 * decodificar dezenas de fotos gigantes enquanto a página rolava, o que travava
 * a rolagem e segurava o servidor PHP (que atende uma requisição por vez).
 *
 * A miniatura é gerada uma única vez (sob demanda no primeiro acesso, ou em
 * lote pelo comando `galleries:thumbnails`) e reaproveitada daí em diante.
 */
class GalleryThumbnailService
{
    public const DISK = 'local';

    /** Mesmo tamanho usado no upload (GalleryController::THUMBNAIL_MAX_DIMENSION). */
    public const MAX_DIMENSION = 480;

    public function __construct(private readonly WatermarkService $watermarkService) {}

    /** Caminho da miniatura sem marca, gerando-a se ainda não existir. */
    public function ensureThumbnail(GalleryImage $image): ?string
    {
        return $this->ensure($image, 'thumbnail_path', $image->path, 'thumbnails');
    }

    /** Caminho da miniatura com marca d'água, gerando-a se houver versão com marca. */
    public function ensureWatermarkedThumbnail(GalleryImage $image): ?string
    {
        if (! $image->watermarked_path) {
            return null;
        }

        return $this->ensure($image, 'watermarked_thumbnail_path', $image->watermarked_path, 'watermarked-thumbnails');
    }

    /** Lado maior da versão "ampliada" (lightbox) quando não há marca d'água. */
    public const PREVIEW_MAX_DIMENSION = 2000;

    /**
     * Versão ampliada leve (máx. 2000 px) para o lightbox da galeria pública
     * quando a galeria NÃO usa marca d'água — sem isso, o cliente baixava o
     * original de vários MB a cada foto aberta. Fica em disco ao lado das
     * outras cópias; não precisa de coluna no banco (o caminho é derivado).
     */
    public function ensurePreview(GalleryImage $image): ?string
    {
        $disk = Storage::disk(self::DISK);
        $storedName = $image->stored_name ?: basename((string) $image->path);
        $relativePath = "galleries/{$image->gallery_id}/previews/{$storedName}";

        if ($disk->exists($relativePath)) {
            return $relativePath;
        }

        if (! $image->path || ! $disk->exists($image->path)) {
            return null;
        }

        // Foto já pequena: não vale gerar cópia.
        if ($image->width && $image->height && max($image->width, $image->height) <= self::PREVIEW_MAX_DIMENSION) {
            return $image->path;
        }

        return $this->watermarkService->resizeCopy(
            $disk->path($image->path),
            $disk->path($relativePath),
            self::PREVIEW_MAX_DIMENSION
        ) ? $relativePath : null;
    }

    private function ensure(GalleryImage $image, string $column, ?string $sourcePath, string $subfolder): ?string
    {
        $disk = Storage::disk(self::DISK);

        if ($image->{$column} && $disk->exists($image->{$column})) {
            return $image->{$column};
        }

        if (! $sourcePath || ! $disk->exists($sourcePath)) {
            return null;
        }

        $storedName = $image->stored_name ?: basename($sourcePath);
        $relativePath = "galleries/{$image->gallery_id}/{$subfolder}/{$storedName}";

        $generated = $this->watermarkService->resizeCopy(
            $disk->path($sourcePath),
            $disk->path($relativePath),
            self::MAX_DIMENSION
        );

        if (! $generated) {
            return null;
        }

        // saveQuietly: sem disparar eventos/observers nem mexer em updated_at
        // da foto por causa de um detalhe interno de armazenamento.
        $image->timestamps = false;
        $image->forceFill([$column => $relativePath])->saveQuietly();
        $image->timestamps = true;

        return $relativePath;
    }
}
