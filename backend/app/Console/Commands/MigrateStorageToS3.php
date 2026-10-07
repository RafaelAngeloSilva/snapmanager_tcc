<?php

namespace App\Console\Commands;

use App\Models\Document;
use App\Models\Gallery;
use App\Models\GalleryImage;
use App\Models\User;
use App\Services\FileStorage;
use App\Support\FileKeys;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\FilesystemOperator;
use Throwable;

/**
 * Copia os arquivos já existentes (disco local de desenvolvimento) para o
 * bucket S3, mantendo EXATAMENTE as mesmas object keys que estão no banco —
 * por isso nenhum registro precisa ser alterado: depois da cópia basta
 * trocar SNAPMANAGER_FILESYSTEM_DISK para "s3".
 *
 *   php artisan storage:migrate-to-s3            # simulação (padrão): nada é enviado
 *   php artisan storage:migrate-to-s3 --apply    # envia de verdade
 *
 * Garantias:
 *   - DRY RUN por padrão; só grava com --apply;
 *   - nunca apaga nem altera os arquivos de origem, nem o banco;
 *   - idempotente: objeto que já está no destino com o mesmo tamanho é
 *     pulado; rodar de novo não duplica nada;
 *   - objeto no destino com tamanho DIFERENTE é conflito: não é
 *     sobrescrito, só reportado;
 *   - cada cópia é conferida (tamanho no destino = tamanho na origem).
 *
 * O que entra: todos os arquivos referenciados no banco (inclusive de
 * registros excluídos logicamente, que o sistema preserva) — fotos
 * (original, miniaturas, marca d'água, prévia), imagem de marca d'água,
 * documentos (original, PDF para assinatura, assinado) e fotos de perfil
 * (inclusive as antigas, do disco "public").
 */
class MigrateStorageToS3 extends Command
{
    protected $signature = 'storage:migrate-to-s3
        {--apply : Envia os arquivos de verdade (sem esta opção é só uma simulação)}
        {--from=local : Disco de origem}
        {--to=s3 : Disco de destino (precisa usar o driver s3)}';

    protected $description = 'Copia os arquivos existentes para o bucket S3 (dry-run por padrão; use --apply para enviar)';

    /** @var array<string, array{source: string, origin: string}> */
    private array $entries = [];

    public function handle(): int
    {
        $from = (string) $this->option('from');
        $to = (string) $this->option('to');
        $apply = (bool) $this->option('apply');

        if ($from === $to) {
            $this->error('Origem e destino são o mesmo disco.');

            return self::FAILURE;
        }

        if (config("filesystems.disks.{$to}.driver") !== 's3') {
            $this->error("O disco de destino \"{$to}\" não usa o driver s3.");

            return self::FAILURE;
        }

        try {
            $target = Storage::disk($to)->getDriver();
        } catch (Throwable $e) {
            $this->error("Não foi possível inicializar o disco \"{$to}\" (".$e::class.'). Verifique o pacote league/flysystem-aws-s3-v3 e as variáveis AWS_*.');

            return self::FAILURE;
        }

        $legacyDisk = (string) config('filesystems.snapmanager.legacy_profile_photo_disk', 'public');
        $this->collect($from, $legacyDisk);

        $this->line($apply
            ? "<fg=yellow>MODO REAL</> — enviando de \"{$from}\" para \"{$to}\"".($this->bucket($to) ? " (bucket {$this->bucket($to)})" : '').'.'
            : "<fg=cyan>SIMULAÇÃO</> (dry-run) — nada será enviado. Use --apply para enviar de \"{$from}\" para \"{$to}\".");
        $this->newLine();

        $result = ['send' => [], 'done' => [], 'present' => [], 'missing' => [], 'conflict' => [], 'error' => []];

        foreach ($this->entries as $key => $entry) {
            try {
                FileStorage::assertSafeKey($key);
                $source = Storage::disk($entry['source'])->getDriver();

                if (! $source->fileExists($key)) {
                    $result['missing'][] = "{$key}  ({$entry['origin']})";

                    continue;
                }

                $sourceSize = $source->fileSize($key);

                if ($target->fileExists($key)) {
                    $targetSize = $target->fileSize($key);
                    $result[$targetSize === $sourceSize ? 'present' : 'conflict'][] = $targetSize === $sourceSize
                        ? $key
                        : "{$key}  (origem {$sourceSize} B, destino {$targetSize} B)";

                    continue;
                }

                if (! $apply) {
                    $result['send'][] = "{$key}  ({$this->humanSize($sourceSize)})";

                    continue;
                }

                $this->copy($source, $target, $key, $sourceSize);
                $result['done'][] = $key;
            } catch (Throwable $e) {
                $result['error'][] = "{$key}  (".FileStorage::sanitizeError($e).')';
            }
        }

        $this->report($result, $apply);

        return $result['error'] === [] ? self::SUCCESS : self::FAILURE;
    }

    private function collect(string $from, string $legacyDisk): void
    {
        GalleryImage::withTrashed()
            ->select(['id', 'gallery_id', 'stored_name', 'path', 'watermarked_path', 'thumbnail_path', 'watermarked_thumbnail_path'])
            ->orderBy('id')
            ->chunkById(200, function ($images) use ($from) {
                foreach ($images as $image) {
                    $this->add($from, $image->path, "foto #{$image->id}: original");
                    $this->add($from, $image->watermarked_path, "foto #{$image->id}: marca d'água");
                    $this->add($from, $image->thumbnail_path, "foto #{$image->id}: miniatura");
                    $this->add($from, $image->watermarked_thumbnail_path, "foto #{$image->id}: miniatura com marca");

                    // Prévia leve: opcional (gerada sob demanda); entra só se existir.
                    if ($image->stored_name) {
                        $preview = FileKeys::gallery($image->gallery_id, FileKeys::GALLERY_PREVIEWS, $image->stored_name);

                        if (Storage::disk($from)->exists($preview)) {
                            $this->add($from, $preview, "foto #{$image->id}: prévia");
                        }
                    }
                }
            });

        Gallery::withTrashed()->whereNotNull('watermark_image_path')->orderBy('id')
            ->each(fn (Gallery $gallery) => $this->add($from, $gallery->watermark_image_path, "galeria #{$gallery->id}: imagem de marca d'água"));

        Document::withTrashed()->orderBy('id')->each(function (Document $document) use ($from) {
            $this->add($from, $document->original_file_path, "documento #{$document->id}: original");
            $this->add($from, $document->signing_file_path, "documento #{$document->id}: PDF para assinatura");
            $this->add($from, $document->signed_file_path, "documento #{$document->id}: assinado");
        });

        User::query()->whereNotNull('profile_photo_path')->orderBy('id')->each(function (User $user) use ($from, $legacyDisk) {
            // Fotos antigas ficavam no disco "public"; as novas, no disco do SnapManager.
            $source = Storage::disk($from)->exists($user->profile_photo_path) ? $from : $legacyDisk;
            $this->add($source, $user->profile_photo_path, "usuário #{$user->id}: foto de perfil");
        });
    }

    private function add(string $sourceDisk, ?string $key, string $origin): void
    {
        if ($key && ! isset($this->entries[$key])) {
            $this->entries[$key] = ['source' => $sourceDisk, 'origin' => $origin];
        }
    }

    private function copy(FilesystemOperator $source, FilesystemOperator $target, string $key, int $size): void
    {
        $stream = $source->readStream($key);

        try {
            $target->writeStream($key, $stream, ['mimetype' => FileStorage::mimeTypeFor($key)]);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        $copied = $target->fileSize($key);

        if ($copied !== $size) {
            throw new \RuntimeException("tamanho no destino ({$copied} B) diferente da origem ({$size} B)");
        }
    }

    /** @param  array<string, list<string>>  $result */
    private function report(array $result, bool $apply): void
    {
        $sections = [
            'send' => 'Seriam enviados',
            'done' => 'Enviados',
            'present' => 'Já no destino (pulados)',
            'conflict' => 'Conflitos — existem no destino com tamanho diferente (NÃO sobrescritos)',
            'missing' => 'Ausentes na origem (referenciados no banco, arquivo não encontrado)',
            'error' => 'Erros',
        ];

        foreach ($sections as $key => $title) {
            if ($result[$key] === []) {
                continue;
            }

            $this->line("<options=bold>{$title}:</>");

            foreach ($result[$key] as $line) {
                $this->line("  - {$line}");
            }

            $this->newLine();
        }

        $this->table(['Situação', 'Quantidade'], [
            ['Arquivos referenciados', count($this->entries)],
            [$apply ? 'Enviados agora' : 'Seriam enviados', count($apply ? $result['done'] : $result['send'])],
            ['Já no destino', count($result['present'])],
            ['Conflitos', count($result['conflict'])],
            ['Ausentes', count($result['missing'])],
            ['Erros', count($result['error'])],
        ]);

        if (! $apply && $result['send'] !== []) {
            $this->info('Simulação concluída. Para enviar de verdade: php artisan storage:migrate-to-s3 --apply');
        }
    }

    private function bucket(string $disk): ?string
    {
        return config("filesystems.disks.{$disk}.bucket") ?: null;
    }

    private function humanSize(int $bytes): string
    {
        return $bytes >= 1048576 ? round($bytes / 1048576, 1).' MB' : max(1, (int) round($bytes / 1024)).' KB';
    }
}
