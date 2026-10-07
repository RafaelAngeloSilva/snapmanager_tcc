<?php

namespace App\Services;

use App\Exceptions\StorageUnavailableException;
use App\Exceptions\StoredFileNotFoundException;
use GuzzleHttp\Promise\Utils as PromiseUtils;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use League\Flysystem\FilesystemException;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\PathPrefixer;
use RuntimeException;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Ponto ÚNICO de acesso aos arquivos persistentes do SnapManager: fotos das
 * galerias (originais, miniaturas, versões com marca d'água), imagem de
 * marca d'água, documentos (original, PDF para assinatura, assinado) e
 * fotos de perfil.
 *
 * O disco vem de config('filesystems.snapmanager.disk'), ou seja, da
 * variável SNAPMANAGER_FILESYSTEM_DISK:
 *   - "local" (padrão, desenvolvimento) → storage/app/private;
 *   - "s3" (produção) → bucket PRIVADO no Amazon S3.
 * Nenhum controller/serviço sabe (nem pergunta) qual dos dois está em uso.
 *
 * Regras:
 *   - Só trabalha com OBJECT KEYS relativas ("galleries/7/thumbnails/x.jpg"),
 *     nunca com caminho absoluto do servidor — é isso que permite o S3.
 *   - Toda leitura/escrita usa a API do Flysystem, que lança exceção em
 *     falha (ao contrário da fachada Storage, configurada com throw=false,
 *     que devolveria null/false em silêncio). Assim dá para diferenciar
 *     "arquivo não existe" (404) de "armazenamento fora do ar" (503).
 *   - Erros vão para o log com operação, disco, object key e mensagem
 *     SANITIZADA (sem credenciais nem parâmetros de URL assinada). O usuário
 *     só vê "Não foi possível carregar o arquivo. Tente novamente."
 *   - Arquivos são servidos PELO LARAVEL (proxy), depois das regras de
 *     autenticação/autorização de cada rota. O bucket nunca fica público e
 *     o navegador nunca recebe credencial nem URL direta do S3.
 */
class FileStorage
{
    /** Tipos servidos pelo sistema (o nome do arquivo é sempre gerado por nós). */
    private const MIME_TYPES = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
        'gif' => 'image/gif',
        'pdf' => 'application/pdf',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    ];

    public function diskName(): string
    {
        return (string) config('filesystems.snapmanager.disk', 'local');
    }

    public function disk(): Filesystem
    {
        $name = $this->diskName();

        try {
            return Storage::disk($name);
        } catch (Throwable $e) {
            // Ex.: disco "s3" sem o pacote league/flysystem-aws-s3-v3
            // instalado, ou sem AWS_BUCKET/AWS_DEFAULT_REGION. É erro de
            // configuração do servidor — mensagem clara no log, sem valores.
            Log::error('Disco de arquivos do SnapManager mal configurado.', [
                'disk' => $name,
                'exception' => $e::class,
            ]);

            throw new RuntimeException(
                "Disco de arquivos \"{$name}\" mal configurado. Para S3: instale league/flysystem-aws-s3-v3 e defina AWS_BUCKET e AWS_DEFAULT_REGION.",
                previous: $e,
            );
        }
    }

    /** Grava conteúdo em memória (ex.: miniatura gerada, PDF convertido). */
    public function put(string $key, string $contents, ?string $mimeType = null): void
    {
        $key = self::assertSafeKey($key);

        try {
            $this->driver()->write($key, $contents, $this->writeConfig($key, $mimeType));
        } catch (FilesystemException $e) {
            $this->fail('write', $key, $e);
        }
    }

    /** Grava um arquivo local (upload recebido) em streaming, sem carregar tudo na memória. */
    public function putFile(string $key, UploadedFile|string $localFile, ?string $mimeType = null): void
    {
        $key = self::assertSafeKey($key);
        $path = $localFile instanceof UploadedFile ? $localFile->getRealPath() : $localFile;
        $stream = @fopen($path, 'rb');

        if ($stream === false) {
            Log::warning('Arquivo local de origem ilegível ao gravar no armazenamento.', ['operation' => 'write', 'key' => $key]);

            throw new StorageUnavailableException('write');
        }

        try {
            $this->driver()->writeStream($key, $stream, $this->writeConfig($key, $mimeType));
        } catch (FilesystemException $e) {
            $this->fail('write', $key, $e);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    /**
     * Grava VÁRIOS objetos de uma vez (ex.: original + 3 versões de uma foto).
     *
     * No S3, as gravações saem EM PARALELO (PutObject assíncrono do SDK da
     * AWS): em vez de pagar a latência de cada requisição em sequência (~150
     * ms por objeto até us-east-2, fora o tempo de envio), todas trafegam ao
     * mesmo tempo. Nos demais discos (local, testes) grava uma a uma, com o
     * mesmo resultado.
     *
     * Espera TODAS terminarem antes de devolver. Se qualquer uma falhar, cada
     * falha vai para o log (sanitizada) e é lançada
     * StorageUnavailableException — quem chama apaga as keys já tentadas.
     *
     * @param  list<array{key: string, contents: string|UploadedFile, mime?: ?string}>  $writes
     *
     * @throws StorageUnavailableException
     */
    public function putMany(array $writes): void
    {
        $disk = $this->disk();

        if (! $disk instanceof AwsS3V3Adapter) {
            foreach ($writes as $write) {
                $write['contents'] instanceof UploadedFile
                    ? $this->putFile($write['key'], $write['contents'], $write['mime'] ?? null)
                    : $this->put($write['key'], $write['contents'], $write['mime'] ?? null);
            }

            return;
        }

        $config = (array) config('filesystems.disks.'.$this->diskName());
        $prefixer = new PathPrefixer((string) ($config['root'] ?? ''));
        $client = $disk->getClient();
        $promises = [];
        $streams = [];

        try {
            foreach ($writes as $write) {
                $key = self::assertSafeKey($write['key']);
                $body = $write['contents'];

                if ($body instanceof UploadedFile) {
                    $body = $streams[] = fopen($body->getRealPath(), 'rb');
                }

                // Mesmos parâmetros que o adaptador do Flysystem usaria: sem
                // ACL (bucket privado, ACLs desativadas), com Content-Type.
                $promises[$key] = $client->putObjectAsync([
                    'Bucket' => $config['bucket'] ?? null,
                    'Key' => $prefixer->prefixPath($key),
                    'Body' => $body,
                    'ContentType' => ($write['mime'] ?? null) ?: self::mimeTypeFor($key),
                ]);
            }

            $results = PromiseUtils::settle($promises)->wait();
        } catch (Throwable $e) {
            $this->fail('write', array_key_last($promises) ?? '(lote)', $e);
        } finally {
            foreach ($streams as $stream) {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
        }

        $failed = array_filter($results, fn (array $result) => $result['state'] !== 'fulfilled');

        foreach ($failed as $key => $result) {
            $reason = $result['reason'];
            $this->log('write', $key, $reason instanceof Throwable ? $reason : new RuntimeException((string) $reason));
        }

        if ($failed !== []) {
            throw new StorageUnavailableException('write');
        }
    }

    /**
     * Conteúdo completo do objeto (para processar: redimensionar, converter,
     * enviar à Clicksign).
     *
     * @throws StoredFileNotFoundException
     * @throws StorageUnavailableException
     */
    public function get(string $key): string
    {
        $key = self::assertSafeKey($key);

        try {
            return $this->driver()->read($key);
        } catch (FilesystemException $e) {
            $this->classifyReadFailure('read', $key, $e);
        }
    }

    /** @throws StorageUnavailableException */
    public function exists(string $key): bool
    {
        $key = self::assertSafeKey($key);

        try {
            return $this->driver()->fileExists($key);
        } catch (FilesystemException $e) {
            $this->fail('exists', $key, $e);
        }
    }

    /** Tamanho em bytes, ou null se o objeto não existe. */
    public function size(string $key): ?int
    {
        $key = self::assertSafeKey($key);

        try {
            return $this->driver()->fileExists($key) ? $this->driver()->fileSize($key) : null;
        } catch (FilesystemException $e) {
            $this->fail('size', $key, $e);
        }
    }

    /**
     * Exclusão definitiva — "melhor esforço": uma falha é registrada no log
     * mas não quebra o fluxo do usuário (o registro já foi tratado; sobra no
     * máximo um objeto órfão, identificável pelo log). Chaves nulas/vazias
     * são ignoradas; apagar o que não existe não é erro.
     *
     * @param  string|array<int, string|null>|null  $keys
     */
    public function delete(string|array|null $keys): void
    {
        foreach (array_filter((array) $keys) as $key) {
            try {
                $this->driver()->delete(self::assertSafeKey($key));
            } catch (FilesystemException $e) {
                $this->log('delete', $key, $e);
            }
        }
    }

    /**
     * Serve o arquivo ao navegador em streaming, SEM expor a object key nem
     * uma URL do S3. Quem chama já aplicou autenticação/autorização.
     *
     * Uma única leitura no armazenamento (sem exists() + size() antes, que
     * no S3 seriam duas requisições HEAD a mais por arquivo).
     *
     * @param  array<string, string>  $headers
     *
     * @throws StoredFileNotFoundException
     * @throws StorageUnavailableException
     */
    public function response(
        string $key,
        string $downloadName,
        ?string $mimeType = null,
        array $headers = [],
        string $disposition = HeaderUtils::DISPOSITION_INLINE,
    ): StreamedResponse {
        $key = self::assertSafeKey($key);

        try {
            $stream = $this->driver()->readStream($key);
        } catch (FilesystemException $e) {
            $this->classifyReadFailure('read', $key, $e);
        }

        $response = new StreamedResponse(function () use ($stream) {
            try {
                fpassthru($stream);
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
        }, 200, $headers);

        $response->headers->set('Content-Type', $mimeType ?: self::mimeTypeFor($key));
        $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition(
            $disposition,
            $downloadName,
            Str::ascii($downloadName) ?: 'arquivo',
        ));
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }

    /**
     * Object keys são geradas pelo próprio sistema (UUID), mas conferimos de
     * novo antes de qualquer operação: nada de "..", caminho absoluto,
     * barra invertida, byte nulo ou chave vazia.
     */
    public static function assertSafeKey(string $key): string
    {
        $valid = $key !== ''
            && ! str_starts_with($key, '/')
            && ! str_contains($key, '\\')
            && ! str_contains($key, "\0")
            && ! preg_match('#(^|/)\.\.?(/|$)#', $key)
            && preg_match('#^[A-Za-z0-9._/-]+$#', $key);

        if (! $valid) {
            throw new InvalidArgumentException('Object key inválida para o armazenamento de arquivos.');
        }

        return $key;
    }

    public static function mimeTypeFor(string $key): string
    {
        return self::MIME_TYPES[strtolower(pathinfo($key, PATHINFO_EXTENSION))] ?? 'application/octet-stream';
    }

    /*
    |--------------------------------------------------------------------------
    | Internos
    |--------------------------------------------------------------------------
    */

    private function driver(): FilesystemOperator
    {
        return $this->disk()->getDriver();
    }

    /**
     * Sem "visibility": num bucket com ACLs desativadas (padrão atual da AWS)
     * enviar ACL faria a gravação falhar, e o bucket já é privado por
     * política. O Content-Type vai junto para o objeto ficar correto no S3.
     *
     * @return array<string, string>
     */
    private function writeConfig(string $key, ?string $mimeType): array
    {
        return ['mimetype' => $mimeType ?: self::mimeTypeFor($key)];
    }

    /**
     * Uma leitura falhou: o objeto não existe (404) ou o armazenamento está
     * indisponível (503)? Confere a existência para decidir.
     */
    private function classifyReadFailure(string $operation, string $key, FilesystemException $e): never
    {
        try {
            $exists = $this->driver()->fileExists($key);
        } catch (FilesystemException $existsError) {
            $this->fail($operation, $key, $existsError);
        }

        if (! $exists) {
            throw new StoredFileNotFoundException;
        }

        $this->fail($operation, $key, $e);
    }

    private function fail(string $operation, string $key, Throwable $e): never
    {
        $this->log($operation, $key, $e);

        throw new StorageUnavailableException($operation);
    }

    private function log(string $operation, string $key, Throwable $e): void
    {
        Log::warning('Falha no armazenamento de arquivos.', [
            'operation' => $operation,
            'disk' => $this->diskName(),
            'key' => $key,
            'error' => self::sanitizeError($e),
        ]);
    }

    /**
     * Mensagem de erro segura para log: sem assinatura/credencial de URL
     * pré-assinada, sem Access Key e sem o secret configurado.
     */
    public static function sanitizeError(Throwable $e): string
    {
        $message = $e::class.': '.$e->getMessage();

        if ($previous = $e->getPrevious()) {
            $message .= ' | '.$previous::class.': '.$previous->getMessage();
        }

        $message = preg_replace('/(X-Amz-[A-Za-z-]+|Signature|AWSAccessKeyId|Credential|Security-Token)=[^&\s"\']+/i', '$1=[redacted]', $message);
        $message = preg_replace('/\b(AKIA|ASIA)[0-9A-Z]{16}\b/', '[access-key]', (string) $message);

        foreach (['filesystems.disks.s3.secret', 'filesystems.disks.s3.key'] as $configKey) {
            $value = (string) config($configKey, '');

            if (strlen($value) >= 8) {
                $message = str_replace($value, '[redacted]', (string) $message);
            }
        }

        return Str::limit((string) $message, 500);
    }
}
