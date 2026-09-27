<?php

namespace App\Console\Commands;

use App\Enums\GalleryStatus;
use App\Models\Gallery;
use Illuminate\Console\Command;

class ExpireGalleries extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'galleries:expire';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Marca como "expired" as galerias ativas cuja data de expiração já passou';

    /**
     * Execute the console command.
     *
     * Not scheduled yet — run manually, or wire it up later via
     * routes/console.php: Schedule::command('galleries:expire')->daily();
     */
    public function handle(): int
    {
        $count = Gallery::query()
            ->where('status', GalleryStatus::Active->value)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->update(['status' => GalleryStatus::Expired->value]);

        $this->info("{$count} galeria(s) marcada(s) como expirada(s).");

        return self::SUCCESS;
    }
}
