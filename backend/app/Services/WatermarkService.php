<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\ImageInterface;

/**
 * Processamento de imagens das galerias (marca d'água e redimensionamento).
 *
 * Trabalha só com o CONTEÚDO das imagens (bytes de entrada → bytes de
 * saída), nunca com caminhos de disco: quem chama lê/grava pelo
 * FileStorage, então o mesmo código funciona com o disco local e com o S3.
 * O único arquivo local lido aqui é a fonte TrueType do sistema.
 *
 * Em caso de falha devolve null (e registra no log) — o upload continua e a
 * foto fica sem a versão derivada, como antes.
 */
class WatermarkService
{
    private const FONT_CANDIDATES = [
        'C:\Windows\Fonts\arial.ttf',
        '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
        '/usr/share/fonts/dejavu/DejaVuSans-Bold.ttf',
    ];

    /** Lado maior da versão com marca d'água (a galeria pública nunca recebe o original). */
    private const MAX_WATERMARK_DIMENSION = 2200;

    private ImageManager $manager;

    public function __construct()
    {
        $this->manager = new ImageManager(new Driver);
    }

    /**
     * Todas as versões de uma foto nova a partir de UMA única decodificação
     * do original (o passo mais caro: 0,5–0,7 s para uma foto de 20+
     * megapixels). Antes, marca d'água e miniatura decodificavam o original
     * cada uma (e a miniatura com marca decodificava o JPEG com marca de
     * novo): ~2,1 s por foto → ~1,3 s.
     *
     *   original ─decodifica─► base (máx. 2200 px)
     *                            ├─ cópia ─► miniatura (480 px)
     *                            └─ marca d'água ─► versão com marca
     *                                                └─► miniatura com marca (480 px)
     *
     * A miniatura sai da base de 2200 px, não do original: mesmo resultado
     * visual numa célula de grade, a uma fração do custo.
     *
     * @param  string|null  $watermarkText  marca de texto (null = sem marca de texto)
     * @param  string|null  $watermarkImage  bytes do logo (null = sem marca de imagem)
     * @return array{thumbnail: ?string, watermarked: ?string, watermarked_thumbnail: ?string}
     */
    public function galleryVariants(
        string $imageBytes,
        string $extension,
        int $thumbnailMaxDimension,
        ?string $watermarkText = null,
        ?string $watermarkImage = null,
    ): array {
        $result = ['thumbnail' => null, 'watermarked' => null, 'watermarked_thumbnail' => null];

        try {
            $base = $this->manager->read($imageBytes);
            unset($imageBytes);
            $base->scaleDown(width: self::MAX_WATERMARK_DIMENSION, height: self::MAX_WATERMARK_DIMENSION);

            $result['thumbnail'] = $this->encode(
                (clone $base)->scaleDown(width: $thumbnailMaxDimension, height: $thumbnailMaxDimension),
                $extension,
            );

            $watermarked = match (true) {
                $watermarkText !== null && $watermarkText !== '' => $this->drawTextWatermark($base, $watermarkText),
                $watermarkImage !== null => $this->placeImageWatermark($base, $watermarkImage),
                default => null,
            };

            if ($watermarked !== null) {
                $result['watermarked'] = $this->encode($watermarked, $extension);
                $result['watermarked_thumbnail'] = $this->encode(
                    $watermarked->scaleDown(width: $thumbnailMaxDimension, height: $thumbnailMaxDimension),
                    $extension,
                );
            }
        } catch (\Throwable $e) {
            Log::warning('Falha ao gerar versões da foto', ['exception' => $e::class]);
        } finally {
            unset($base, $watermarked);
            $this->releaseMemory();
        }

        return $result;
    }

    /** Cópia reduzida (miniatura 480 px, prévia 2000 px), mantendo a proporção. */
    public function resized(string $imageBytes, int $maxDimension, string $extension): ?string
    {
        try {
            $image = $this->manager->read($imageBytes);
            $image->scaleDown(width: $maxDimension, height: $maxDimension);

            return $this->encode($image, $extension);
        } catch (\Throwable $e) {
            Log::warning('Falha ao gerar miniatura', ['exception' => $e::class]);

            return null;
        } finally {
            $this->releaseMemory();
        }
    }

    /** Desenha a marca de texto na própria imagem (já redimensionada) e a devolve. */
    private function drawTextWatermark(ImageInterface $image, string $text): ImageInterface
    {
        $width = $image->width();
        $height = $image->height();

        $fontSize = (int) max(16, min(60, round(sqrt($width * $height) / 18)));
        $fontFile = $this->resolveFontFile();

        $columns = 4;
        $rows = 3;
        $stepX = (int) ($width / $columns);
        $stepY = (int) ($height / $rows);

        for ($row = -1; $row <= $rows; $row++) {
            // Linhas alternadas deslocadas na horizontal — padrão "tijolo"
            // diagonal, mais difícil de recortar fora do que uma marca única
            // centralizada.
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

        return $image;
    }

    /** Posiciona o logo (40% da largura, 60% de opacidade) no centro da imagem e a devolve. */
    private function placeImageWatermark(ImageInterface $image, string $watermarkBytes): ImageInterface
    {
        $watermark = $this->manager->read($watermarkBytes);
        $watermark->scale(width: (int) round($image->width() * 0.4));

        return $image->place($watermark, 'center', 0, 0, 60);
    }

    private function encode(ImageInterface $image, string $extension): string
    {
        return $image->encodeByExtension(strtolower($extension) ?: 'jpg')->toString();
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

    /** Fotos de 4000+ px ocupam dezenas de MB decodificadas: libera logo. */
    private function releaseMemory(): void
    {
        gc_collect_cycles();
    }
}
