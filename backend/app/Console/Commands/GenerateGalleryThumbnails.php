<?php

namespace App\Console\Commands;

use App\Models\GalleryImage;
use App\Services\GalleryThumbnailService;
use Illuminate\Console\Command;

/**
 * Gera de uma vez as miniaturas que faltam (fotos antigas ou uploads em que a
 * geração falhou). Pode ser rodado quantas vezes quiser: fotos que já têm
 * miniatura são puladas.
 *
 *   php artisan galleries:thumbnails
 */
class GenerateGalleryThumbnails extends Command
{
    protected $signature = 'galleries:thumbnails';

    protected $description = 'Gera as miniaturas que estão faltando nas fotos das galerias';

    public function handle(GalleryThumbnailService $thumbnails): int
    {
        $query = GalleryImage::query()
            ->where(fn ($q) => $q
                ->whereNull('thumbnail_path')
                ->orWhere(fn ($q) => $q->whereNotNull('watermarked_path')->whereNull('watermarked_thumbnail_path')));

        $total = (clone $query)->count();

        if ($total === 0) {
            $this->info('Todas as fotos já têm miniatura.');

            return self::SUCCESS;
        }

        $this->info("Gerando miniaturas para {$total} foto(s)...");
        $bar = $this->output->createProgressBar($total);
        $failed = 0;

        $query->orderBy('id')->chunkById(50, function ($images) use ($thumbnails, $bar, &$failed) {
            foreach ($images as $image) {
                $ok = $thumbnails->ensureThumbnail($image) !== null;

                if ($image->watermarked_path) {
                    $ok = $thumbnails->ensureWatermarkedThumbnail($image) !== null && $ok;
                }

                $failed += $ok ? 0 : 1;
                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine();

        $failed > 0
            ? $this->warn("Concluído, mas {$failed} foto(s) não puderam ser processadas (arquivo ausente ou erro no GD — ver storage/logs/laravel.log).")
            : $this->info('Miniaturas geradas com sucesso.');

        return self::SUCCESS;
    }
}
