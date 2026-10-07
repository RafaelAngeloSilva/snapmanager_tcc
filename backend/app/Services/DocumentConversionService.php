<?php

namespace App\Services;

use App\Exceptions\DocumentConversionException;
use App\Exceptions\StorageUnavailableException;
use App\Exceptions\StoredFileNotFoundException;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Symfony\Component\Process\ExecutableFinder;
use Throwable;

/**
 * Converte DOCX em PDF com o LibreOffice em modo headless.
 *
 * Segurança e isolamento:
 *  - o executável vem SÓ da configuração (DOCUMENT_CONVERTER_BINARY) —
 *    nada que o usuário envie escolhe programa ou argumento;
 *  - o comando é um ARRAY de argumentos (Symfony Process escapa cada um;
 *    nada é concatenado em linha de shell). O nome original do arquivo nem
 *    chega aqui: a cópia de trabalho sempre se chama "document.docx";
 *  - cada conversão tem diretório temporário e PERFIL do LibreOffice
 *    próprios (-env:UserInstallation), então conversões simultâneas não
 *    disputam o mesmo perfil nem se conectam a outra instância aberta;
 *  - o perfil nasce com macros desativadas (MacroSecurityLevel = 3,
 *    DisableMacrosExecution = true) — o DOCX é um arquivo não confiável;
 *  - timeout; código de saída, existência e assinatura "%PDF-" conferidos;
 *  - o diretório temporário é apagado SEMPRE (finally), com sucesso ou não.
 *
 * Mensagens para o usuário não trazem caminhos, comando nem saída do
 * processo; esses detalhes vão só para o log.
 */
class DocumentConversionService
{
    public function __construct(private readonly FileStorage $files) {}

    /** Nome fixo da cópia de trabalho: o nome enviado pelo usuário nunca vira argumento. */
    private const WORK_FILE = 'document.docx';

    public function isAvailable(): bool
    {
        return $this->resolveBinary() !== null;
    }

    /**
     * Converte o DOCX guardado em `$sourcePath` e grava o PDF em
     * `$targetPath` — ambos OBJECT KEYS do armazenamento persistente (disco
     * local ou S3, via FileStorage). O LibreOffice nunca recebe uma URL:
     *
     *   armazenamento → cópia no diretório temporário isolado → LibreOffice
     *   → PDF temporário → validação → armazenamento → limpeza (finally).
     *
     * Devolve o tamanho do PDF, em bytes.
     *
     * @throws DocumentConversionException
     */
    public function convertToPdf(string $sourcePath, string $targetPath): int
    {
        $binary = $this->resolveBinary();

        if ($binary === null) {
            Log::warning('Conversão DOCX → PDF indisponível: LibreOffice não encontrado. Configure DOCUMENT_CONVERTER_BINARY.');

            throw DocumentConversionException::unavailable();
        }

        $workDir = $this->tempRoot().DIRECTORY_SEPARATOR.Str::uuid();
        $inDir = $workDir.DIRECTORY_SEPARATOR.'in';
        $outDir = $workDir.DIRECTORY_SEPARATOR.'out';
        $profileDir = $workDir.DIRECTORY_SEPARATOR.'profile';

        try {
            File::ensureDirectoryExists($inDir);
            File::ensureDirectoryExists($outDir);
            File::ensureDirectoryExists($profileDir.DIRECTORY_SEPARATOR.'user');

            $input = $inDir.DIRECTORY_SEPARATOR.self::WORK_FILE;
            // Baixa o DOCX do armazenamento (S3 ou local) para a área de
            // trabalho isolada desta conversão.
            File::put($input, $this->files->get($sourcePath));
            File::put($profileDir.DIRECTORY_SEPARATOR.'user'.DIRECTORY_SEPARATOR.'registrymodifications.xcu', $this->hardenedProfile());

            $result = Process::path($workDir)
                ->timeout($this->timeout())
                // HOME/TMP no diretório isolado: no Linux o LibreOffice
                // escreveria em ~/.config se pudesse.
                ->env(['HOME' => $workDir, 'TMPDIR' => $workDir, 'TMP' => $workDir, 'TEMP' => $workDir])
                ->run([
                    $binary,
                    '-env:UserInstallation='.$this->fileUrl($profileDir),
                    '--headless',
                    '--invisible',
                    '--nologo',
                    '--nodefault',
                    '--nolockcheck',
                    '--norestore',
                    '--convert-to',
                    'pdf:writer_pdf_Export',
                    '--outdir',
                    $outDir,
                    $input,
                ]);

            if (! $result->successful()) {
                Log::warning('Conversão DOCX → PDF falhou.', [
                    'exit_code' => $result->exitCode(),
                    'stderr' => Str::limit(trim($result->errorOutput()), 500),
                ]);

                throw DocumentConversionException::failed();
            }

            $output = $outDir.DIRECTORY_SEPARATOR.pathinfo(self::WORK_FILE, PATHINFO_FILENAME).'.pdf';

            if (! is_file($output) || filesize($output) === 0) {
                Log::warning('Conversão DOCX → PDF terminou sem gerar o PDF.');

                throw DocumentConversionException::failed();
            }

            $pdf = file_get_contents($output);

            if (! str_starts_with($pdf, '%PDF-')) {
                Log::warning('Conversão DOCX → PDF gerou um arquivo que não é PDF.');

                throw DocumentConversionException::failed();
            }

            $this->files->put($targetPath, $pdf, 'application/pdf');

            return strlen($pdf);
        } catch (ProcessTimedOutException) {
            Log::warning('Conversão DOCX → PDF excedeu o tempo limite.', ['timeout' => $this->timeout()]);

            throw DocumentConversionException::timeout();
        } catch (DocumentConversionException|StorageUnavailableException|StoredFileNotFoundException $e) {
            // Falha do armazenamento: já registrada (sanitizada) pelo
            // FileStorage e com mensagem própria para o usuário.
            throw $e;
        } catch (Throwable $e) {
            // Ex.: falha ao iniciar o processo, disco cheio. Só a classe vai
            // para o log — a mensagem pode conter caminhos do servidor.
            Log::error('Erro inesperado na conversão DOCX → PDF.', ['exception' => $e::class]);

            throw DocumentConversionException::failed();
        } finally {
            File::deleteDirectory($workDir);
        }
    }

    /**
     * Caminho do executável: definido em DOCUMENT_CONVERTER_BINARY (caminho
     * completo ou nome do programa). Sem configuração, procura "soffice" no
     * PATH. Nenhum caminho de máquina específica fica no código.
     */
    private function resolveBinary(): ?string
    {
        $configured = trim((string) config('services.document_converter.binary', ''));
        $candidate = $configured !== '' ? $configured : 'soffice';

        // Caminho informado (contém separador): tem que existir e ser executável.
        if (str_contains($candidate, '/') || str_contains($candidate, '\\')) {
            return is_file($candidate) && is_executable($candidate) ? $candidate : null;
        }

        return (new ExecutableFinder)->find($candidate);
    }

    private function timeout(): int
    {
        return max(10, (int) config('services.document_converter.timeout', 90));
    }

    /**
     * Raiz dos diretórios de trabalho TEMPORÁRIOS (apagados ao fim de cada
     * conversão). No Railway é o disco efêmero do container — nada
     * persistente fica aqui.
     */
    private function tempRoot(): string
    {
        $configured = trim((string) config('services.document_converter.temp_path', ''));

        return $configured !== '' ? rtrim($configured, '/\\') : storage_path('framework'.DIRECTORY_SEPARATOR.'document-conversions');
    }

    /**
     * Converte um caminho local em URL file:// (formato exigido por
     * -env:UserInstallation), em Windows (C:\...) e Linux (/...).
     */
    private function fileUrl(string $path): string
    {
        $normalized = str_replace('\\', '/', $path);
        $segments = array_map(
            // Mantém "C:" (unidade do Windows) e codifica o resto (espaços etc.).
            fn (string $segment) => preg_match('/^[A-Za-z]:$/', $segment) ? $segment : rawurlencode($segment),
            explode('/', $normalized),
        );

        $encoded = implode('/', $segments);

        return 'file://'.(str_starts_with($encoded, '/') ? '' : '/').$encoded;
    }

    /**
     * Perfil mínimo do LibreOffice com macros bloqueadas: nível de
     * segurança "muito alto" e execução de macros desativada.
     */
    private function hardenedProfile(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<oor:items xmlns:oor="http://openoffice.org/2001/registry" xmlns:xs="http://www.w3.org/2001/XMLSchema" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">
<item oor:path="/org.openoffice.Office.Common/Security/Scripting"><prop oor:name="MacroSecurityLevel" oor:op="fuse"><value>3</value></prop></item>
<item oor:path="/org.openoffice.Office.Common/Security/Scripting"><prop oor:name="DisableMacrosExecution" oor:op="fuse"><value>true</value></prop></item>
</oor:items>
XML;
    }
}
