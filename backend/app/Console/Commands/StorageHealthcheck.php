<?php

namespace App\Console\Commands;

use App\Exceptions\StorageUnavailableException;
use App\Exceptions\StoredFileNotFoundException;
use App\Services\FileStorage;
use App\Support\AwsCredentials;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Throwable;

/**
 * Teste mínimo do armazenamento de arquivos, pelo MESMO FileStorage e pela
 * MESMA configuração da aplicação: grava, confere existência, lê e apaga um
 * objeto temporário em galleries/_healthcheck/ (prefixo já permitido pela
 * política IAM do SnapManager).
 *
 *   php artisan storage:healthcheck            # objeto pequeno (texto)
 *   php artisan storage:healthcheck --size=5   # também mede o envio de 5 MB
 *
 * Mostra disco, região e bucket — NUNCA credenciais. Em caso de falha, o
 * detalhe técnico (sanitizado) fica no storage/logs/laravel.log.
 */
class StorageHealthcheck extends Command
{
    protected $signature = 'storage:healthcheck
        {--size=0 : Também envia um objeto de N MB para medir a velocidade de upload (0 = só o teste pequeno)}';

    protected $description = 'Testa gravar/ler/apagar um objeto no armazenamento configurado (local ou S3), sem expor credenciais';

    public function handle(FileStorage $files): int
    {
        $disk = $files->diskName();
        $driver = config("filesystems.disks.{$disk}.driver");

        $this->line("Disco ativo (SNAPMANAGER_FILESYSTEM_DISK): <options=bold>{$disk}</> (driver {$driver})");

        if ($driver === 's3') {
            $this->line('Região: '.(config("filesystems.disks.{$disk}.region") ?: '(vazia)'));
            $this->line('Bucket: '.(config("filesystems.disks.{$disk}.bucket") ?: '(vazio)'));
            $this->line('Credencial definida: '.(config("filesystems.disks.{$disk}.key") && config("filesystems.disks.{$disk}.secret") ? 'sim' : 'NÃO'));
            $this->line('Endpoint customizado: '.(config("filesystems.disks.{$disk}.endpoint") ? 'sim' : 'não'));

            // Formato das variáveis, sem mostrar valores.
            foreach (AwsCredentials::diagnose() as $name => $problem) {
                $problem === null
                    ? $this->line("  ✓ {$name}: formato OK")
                    : $this->warn("  ✗ {$name}: {$problem}");
            }
        }

        $this->line('Cache de configuração: '.(app()->configurationIsCached() ? 'SIM — rode "php artisan config:clear" se mudou o .env' : 'não'));
        $this->newLine();

        $ok = $this->check($files, 'galleries/_healthcheck/'.Str::uuid().'.txt', 'SnapManager healthcheck '.now()->toIso8601String());

        $megabytes = max(0, (int) $this->option('size'));

        if ($ok && $megabytes > 0) {
            $this->newLine();
            $this->line("Teste de velocidade: {$megabytes} MB");
            $ok = $this->check($files, 'galleries/_healthcheck/'.Str::uuid().'.bin', random_bytes($megabytes * 1024 * 1024));
        }

        $this->newLine();
        $ok
            ? $this->info('Armazenamento OK: gravar, conferir, ler e apagar funcionaram.')
            : $this->error('Falha no armazenamento. Veja a etapa acima e o detalhe (sanitizado) em storage/logs/laravel.log.');

        return $ok ? self::SUCCESS : self::FAILURE;
    }

    private function check(FileStorage $files, string $key, string $contents): bool
    {
        $this->line("Object key de teste: {$key}");
        $written = false;

        try {
            $this->step('gravar', fn () => $files->put($key, $contents, 'application/octet-stream'), strlen($contents));
            $written = true;

            $this->step('conferir existência', function () use ($files, $key) {
                if (! $files->exists($key)) {
                    throw new StoredFileNotFoundException;
                }
            });

            $this->step('ler', function () use ($files, $key, $contents) {
                if ($files->get($key) !== $contents) {
                    throw new \RuntimeException('conteúdo lido diferente do gravado');
                }
            });

            $this->step('apagar', fn () => $files->delete($key));

            if ($files->exists($key)) {
                $this->error('  objeto continua existindo depois de apagar (sem permissão de DeleteObject?)');

                return false;
            }

            return true;
        } catch (Throwable $e) {
            $message = match (true) {
                $e instanceof StorageUnavailableException => 'armazenamento recusou ou não respondeu (credencial, permissão IAM, bucket, região ou rede)',
                $e instanceof StoredFileNotFoundException => 'objeto não encontrado logo após gravar',
                default => FileStorage::sanitizeError($e),
            };

            $this->error("  FALHOU: {$message}");

            if ($written) {
                $files->delete($key);
            }

            return false;
        }
    }

    private function step(string $label, callable $operation, ?int $bytes = null): void
    {
        $start = microtime(true);
        $operation();
        $seconds = microtime(true) - $start;

        $speed = $bytes !== null && $bytes > 1024 * 1024 && $seconds > 0
            ? sprintf(' (%.1f MB/s)', $bytes / 1048576 / $seconds)
            : '';

        $this->line(sprintf('  ✓ %-20s %6.2f s%s', $label, $seconds, $speed));
    }
}
