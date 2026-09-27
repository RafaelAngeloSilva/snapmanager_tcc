<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;


class WatermarkService
{
    
    private const FONT_CANDIDATES = [
        'C:\Windows\Fonts\arial.ttf',
        '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
        '/usr/share/fonts/dejavu/DejaVuSans-Bold.ttf',
    ];

    
    private const MAX_WATERMARK_DIMENSION = 2200;

    private ImageManager $manager;

    public function __construct()
    {
        $this->manager = new ImageManager(new Driver());
    }

    
    public function applyTextWatermark(string $sourcePath, string $destPath, string $text): bool
    {
        try {
            $image = $this->manager->read($sourcePath);
            $image->scaleDown(width: self::MAX_WATERMARK_DIMENSION, height: self::MAX_WATERMARK_DIMENSION);
            $width = $image->width();
            $height = $image->height();

            $fontSize = (int) max(16, min(60, round(sqrt($width * $height) / 18)));
            $fontFile = $this->resolveFontFile();

            $columns = 4;
            $rows = 3;
            $stepX = (int) ($width / $columns);
            $stepY = (int) ($height / $rows);

            for ($row = -1; $row <= $rows; $row++) {
                // Linhas alternadas deslocadas na horizontal — padrão
                // "tijolo" diagonal, mais difícil de recortar fora do que
                // uma marca única centralizada.
                $rowOffset = ($row % 2 === 0) ? 0 : (int) ($stepX / 2);

                for ($col = -1; $col <= $columns; $col++) {
                    $x = $col * $stepX + $rowOffset;
                    $y = $row * $stepY;

                    $image->text($text, $x, $y, function ($font) use ($fontFile, $fontSize) {
                        if ($fontFile) {
                            $font->file($fontFile);
                        }
                        $font->size($fontSize);
                        $font->color('rgba(255, 255, 255, 0.4)');
                        $font->align('center');
                        $font->valign('middle');
                        $font->angle(-30);
                    });
                }
            }

            $this->ensureDirectoryExists($destPath);
            $image->save($destPath);

            return true;
        } catch (\Throwable $e) {
            Log::warning('Falha ao gerar marca d\'água de texto', ['message' => $e->getMessage()]);

            return false;
        } finally {
            $this->releaseMemory($image ?? null);
        }
    }

    
    public function applyImageWatermark(string $sourcePath, string $destPath, string $watermarkImagePath): bool
    {
        try {
            $image = $this->manager->read($sourcePath);
            $image->scaleDown(width: self::MAX_WATERMARK_DIMENSION, height: self::MAX_WATERMARK_DIMENSION);

            $watermark = $this->manager->read($watermarkImagePath);
            $targetWidth = (int) round($image->width() * 0.4);
            $watermark->scale(width: $targetWidth);

            $image->place($watermark, 'center', 0, 0, 60);

            $this->ensureDirectoryExists($destPath);
            $image->save($destPath);

            return true;
        } catch (\Throwable $e) {
            Log::warning('Falha ao gerar marca d\'água de imagem', ['message' => $e->getMessage()]);

            return false;
        } finally {
            $this->releaseMemory($image ?? null, $watermark ?? null);
        }
    }

    /**
     * Cópia redimensionada simples, sem marca d'água — usada pra gerar
     * miniaturas (ver GalleryController): a grade de fotos nunca precisa do
     * arquivo original em resolução total, só de algo pequeno o bastante
     * pra uma célula da grade.
     */
    public function resizeCopy(string $sourcePath, string $destPath, int $maxDimension): bool
    {
        try {
            $image = $this->manager->read($sourcePath);
            $image->scaleDown(width: $maxDimension, height: $maxDimension);

            $this->ensureDirectoryExists($destPath);
            $image->save($destPath);

            return true;
        } catch (\Throwable $e) {
            Log::warning('Falha ao gerar miniatura', ['message' => $e->getMessage()]);

            return false;
        } finally {
            $this->releaseMemory($image ?? null);
        }
    }

    private function resolveFontFile(): ?string
    {
        foreach (self::FONT_CANDIDATES as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Forces the decoded GD bitmaps to be freed right away instead of
     * waiting for PHP's garbage collector to get around to them — important
     * here because a single gallery upload processes several photos back to
     * back in the same request/process (see GalleryController::uploadImages).
     */
    private function releaseMemory(?object ...$images): void
    {
        unset($images);
        gc_collect_cycles();
    }

    private function ensureDirectoryExists(string $destPath): void
    {
        $directory = dirname($destPath);

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }
    }
}
