<?php

namespace Tests\Feature;

use App\Enums\DocumentConversionStatus;
use App\Enums\DocumentFileType;
use App\Enums\DocumentStatus;
use App\Models\Client;
use App\Models\Document;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Process\PendingProcess;
use Illuminate\Process\ProcessResult;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\Process\Exception\ProcessTimedOutException as SymfonyTimedOut;
use Symfony\Component\Process\Process as SymfonyProcess;
use Tests\TestCase;
use ZipArchive;

/**
 * Upload de Word (.docx) com conversão para PDF.
 *
 * O LibreOffice NÃO é necessário: Process::fake() intercepta o comando e
 * simula o conversor (gravando um PDF no --outdir, falhando ou estourando o
 * tempo). O executável configurado é o próprio PHP_BINARY — um arquivo
 * executável real, que satisfaz a checagem de "existe e é executável" sem
 * nunca ser executado. A Clicksign, como sempre, fica em Http::fake().
 */
class DocxConversionTest extends TestCase
{
    use RefreshDatabase;

    private const CONVERTED_PDF = "%PDF-1.7\n% convertido pelo fake\n%%EOF\n";

    private string $tempRoot;

    private Client $client;

    /** @var array<int, array<int, string>> comandos recebidos pelo conversor fake */
    private array $commands = [];

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Storage::fake('local');

        $this->tempRoot = storage_path('framework/testing/document-conversions-'.uniqid());

        config([
            'services.clicksign.base_url' => 'https://sandbox.clicksign.com/api/v3',
            'services.clicksign.access_token' => 'token-ficticio',
            'services.document_converter.binary' => PHP_BINARY,
            'services.document_converter.timeout' => 30,
            'services.document_converter.temp_path' => $this->tempRoot,
        ]);

        $this->client = Client::factory()->create();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->tempRoot);

        parent::tearDown();
    }

    // ---------------------------------------------------------------- helpers

    private function actingAsRole(string $role): User
    {
        $user = User::factory()->create(['role' => $role]);
        Sanctum::actingAs($user);

        return $user;
    }

    /**
     * Gera um .docx de verdade (ZIP Office Open XML mínimo).
     *
     * @param  array<string, string|null>  $overrides  entrada => conteúdo (null remove)
     */
    private function docx(string $name = 'contrato.docx', array $overrides = []): UploadedFile
    {
        $entries = [
            '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>',
            '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>',
            'word/document.xml' => '<?xml version="1.0" encoding="UTF-8"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>Contrato de teste</w:t></w:r></w:p></w:body></w:document>',
            'word/_rels/document.xml.rels' => '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId9" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/hyperlink" Target="https://example.com" TargetMode="External"/></Relationships>',
            ...$overrides,
        ];

        $path = tempnam(sys_get_temp_dir(), 'docx');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);

        foreach ($entries as $entry => $content) {
            if ($content !== null) {
                $zip->addFromString($entry, $content);
            }
        }

        $zip->close();

        return new UploadedFile($path, $name, null, null, true);
    }

    private function pdf(string $name = 'contrato.pdf', string $content = "%PDF-1.4\n%%EOF\n"): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $content);
    }

    /** Conversor fake de sucesso: grava o PDF onde o LibreOffice gravaria. */
    private function fakeConverterSuccess(): void
    {
        Process::fake(function (PendingProcess $process) {
            $this->commands[] = $process->command;
            $outDir = $process->command[array_search('--outdir', $process->command, true) + 1];
            file_put_contents($outDir.DIRECTORY_SEPARATOR.'document.pdf', self::CONVERTED_PDF);

            return Process::result(output: 'convert document.docx -> document.pdf');
        });
    }

    /** @param  array<string, mixed>  $overrides */
    private function upload(UploadedFile $file, array $overrides = []): \Illuminate\Testing\TestResponse
    {
        return $this->post('/api/documents', [
            'title' => 'Contrato de casamento',
            'type' => 'wedding',
            'client_id' => $this->client->id,
            'file' => $file,
            'signers' => [['name' => 'Maria Cliente', 'channel' => 'email', 'email' => 'maria@example.com']],
            ...$overrides,
        ], ['Accept' => 'application/json']);
    }

    private function assertTempDirectoryIsEmpty(): void
    {
        $leftovers = is_dir($this->tempRoot) ? array_diff(scandir($this->tempRoot), ['.', '..']) : [];

        $this->assertSame([], array_values($leftovers), 'Sobraram arquivos temporários da conversão.');
    }

    private function fakeClicksign(): void
    {
        Http::fake(['*' => Http::response(['data' => ['id' => 'id-'.uniqid(), 'type' => 'x', 'attributes' => ['status' => 'running']]], 201)]);
    }

    // ------------------------------------------------ 1. PDF: fluxo de sempre

    public function test_pdf_upload_keeps_the_existing_flow_without_conversion(): void
    {
        Process::fake();
        $this->actingAsRole('attendant');

        $response = $this->upload($this->pdf())->assertCreated()
            ->assertJsonPath('data.file.type', 'pdf')
            ->assertJsonPath('data.signing_file.same_as_original', true)
            ->assertJsonPath('data.signing_file.ready', true)
            ->assertJsonPath('data.conversion', null)
            ->assertJsonPath('data.abilities.send', true);

        $document = Document::findOrFail($response->json('data.id'));
        $this->assertNull($document->signing_file_path);
        $this->assertSame($document->original_file_path, $document->signingFilePath());
        Process::assertNothingRan();
    }

    // ------------------------------------------------ 2, 7, 12, 13. DOCX convertido

    public function test_docx_upload_keeps_the_original_and_stores_a_converted_pdf_privately(): void
    {
        $this->fakeConverterSuccess();
        $this->actingAsRole('attendant');

        $response = $this->upload($this->docx('Contrato Casamento.docx'))->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.file.type', 'docx')
            ->assertJsonPath('data.file.type_label', 'Word')
            ->assertJsonPath('data.file.name', 'contrato-casamento.docx')
            ->assertJsonPath('data.signing_file.same_as_original', false)
            ->assertJsonPath('data.signing_file.ready', true)
            ->assertJsonPath('data.signing_file.name', 'contrato-casamento.pdf')
            ->assertJsonPath('data.conversion.status', 'converted')
            ->assertJsonPath('data.conversion.label', 'Conversão concluída')
            ->assertJsonPath('data.abilities.send', true)
            ->assertJsonPath('message', null);

        $document = Document::findOrFail($response->json('data.id'));

        // Três conceitos separados: original DOCX, PDF para assinatura, (assinado depois).
        $this->assertSame(DocumentFileType::Docx, $document->original_file_type);
        $this->assertMatchesRegularExpression('#^documents/original/[0-9a-f-]{36}\.docx$#', $document->original_file_path);
        $this->assertMatchesRegularExpression('#^documents/signing/[0-9a-f-]{36}\.pdf$#', $document->signing_file_path);
        Storage::disk('local')->assertExists([$document->original_file_path, $document->signing_file_path]);
        $this->assertStringStartsWith('PK', Storage::disk('local')->get($document->original_file_path));
        $this->assertSame(self::CONVERTED_PDF, Storage::disk('local')->get($document->signing_file_path));
        $this->assertSame(strlen(self::CONVERTED_PDF), $document->signing_file_size);

        // Caminhos no disco nunca aparecem na resposta.
        $this->assertStringNotContainsString($document->original_file_path, $response->getContent());
        $this->assertStringNotContainsString($document->signing_file_path, $response->getContent());

        $this->assertDatabaseHas('document_events', ['document_id' => $document->id, 'event' => 'file_converted']);
        // Converter não fala com a Clicksign.
        Http::assertNothingSent();
    }

    public function test_converter_runs_isolated_with_macros_disabled_and_never_sees_the_user_filename(): void
    {
        $profiles = [];

        Process::fake(function (PendingProcess $process) use (&$profiles) {
            $this->commands[] = $process->command;

            $profileArg = collect($process->command)->first(fn ($arg) => str_starts_with($arg, '-env:UserInstallation=file://'));
            $this->assertNotNull($profileArg, 'Cada conversão precisa de um perfil do LibreOffice próprio.');
            $profiles[] = $profileArg;

            // O perfil isolado nasce com macros bloqueadas.
            $profileDir = dirname($process->command[array_search('--outdir', $process->command, true) + 1]).DIRECTORY_SEPARATOR.'profile';
            $registry = file_get_contents($profileDir.DIRECTORY_SEPARATOR.'user'.DIRECTORY_SEPARATOR.'registrymodifications.xcu');
            $this->assertStringContainsString('DisableMacrosExecution', $registry);
            $this->assertStringContainsString('<prop oor:name="MacroSecurityLevel" oor:op="fuse"><value>3</value>', $registry);

            $outDir = $process->command[array_search('--outdir', $process->command, true) + 1];
            file_put_contents($outDir.DIRECTORY_SEPARATOR.'document.pdf', self::CONVERTED_PDF);

            return Process::result();
        });

        $this->actingAsRole('admin');

        $this->upload($this->docx('contrato"; rm -rf ~; echo ".docx'))->assertCreated();
        $this->upload($this->docx('outro.docx'))->assertCreated();

        $this->assertCount(2, $this->commands);
        $this->assertNotSame($profiles[0], $profiles[1], 'Conversões simultâneas não podem dividir o perfil.');

        foreach ($this->commands as $command) {
            // Executável da configuração, argumentos separados, nome de trabalho fixo.
            $this->assertSame(PHP_BINARY, $command[0]);
            $this->assertContains('--headless', $command);
            $this->assertContains('pdf:writer_pdf_Export', $command);
            $this->assertStringEndsWith('document.docx', end($command));
            $this->assertSame([], array_values(array_filter($command, fn ($arg) => str_contains($arg, 'rm -rf'))));
        }
    }

    // ------------------------------------------------ 3-6. validação do upload

    public function test_rejects_files_that_only_pretend_to_be_docx(): void
    {
        Process::fake();
        $this->actingAsRole('admin');

        // PDF renomeado para .docx.
        $this->upload($this->pdf('contrato.docx', "%PDF-1.4\n%%EOF"))->assertStatus(422)->assertJsonValidationErrors('file');

        // ZIP qualquer renomeado para .docx (sem estrutura do Word).
        $this->upload($this->docx('contrato.docx', [
            '[Content_Types].xml' => null,
            '_rels/.rels' => null,
            'word/document.xml' => null,
            'word/_rels/document.xml.rels' => null,
            'fotos/leia-me.txt' => 'não é Word',
        ]))->assertStatus(422)->assertJsonValidationErrors('file');

        // Texto com extensão .docx.
        $this->upload(UploadedFile::fake()->createWithContent('contrato.docx', 'texto puro'))->assertStatus(422)->assertJsonValidationErrors('file');

        $this->assertSame(0, Document::count());
        Process::assertNothingRan();
    }

    public function test_rejects_malformed_docx(): void
    {
        Process::fake();
        $this->actingAsRole('admin');

        $path = tempnam(sys_get_temp_dir(), 'bad');
        file_put_contents($path, "PK\x03\x04".random_bytes(512));

        $this->upload(new UploadedFile($path, 'contrato.docx', null, null, true))
            ->assertStatus(422)->assertJsonValidationErrors('file');

        Process::assertNothingRan();
    }

    public function test_rejects_macro_documents_in_every_disguise(): void
    {
        Process::fake();
        $this->actingAsRole('admin');

        // Extensão .docm.
        $this->upload($this->docx('contrato.docm'))->assertStatus(422)->assertJsonValidationErrors('file');

        // .docm renomeado para .docx (tipo principal "macroEnabled").
        $this->upload($this->docx('contrato.docx', [
            '[Content_Types].xml' => '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Override PartName="/word/document.xml" ContentType="application/vnd.ms-word.document.macroEnabled.main+xml"/></Types>',
        ]))->assertStatus(422)->assertJsonPath('errors.file.0', 'Documentos Word com macros não são aceitos. Salve como .docx comum (sem macros).');

        // Projeto VBA escondido dentro de um .docx.
        $this->upload($this->docx('contrato.docx', ['word/vbaProject.bin' => 'macro']))->assertStatus(422)->assertJsonValidationErrors('file');

        // Outros formatos de escritório ficam de fora nesta etapa.
        foreach (['contrato.doc', 'contrato.rtf', 'contrato.odt'] as $name) {
            $this->upload(UploadedFile::fake()->createWithContent($name, 'conteúdo'))->assertStatus(422)->assertJsonValidationErrors('file');
        }

        $this->assertSame(0, Document::count());
    }

    public function test_rejects_docx_with_external_links_that_the_converter_would_fetch(): void
    {
        Process::fake();
        $this->actingAsRole('admin');

        $this->upload($this->docx('contrato.docx', [
            'word/_rels/document.xml.rels' => '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId5" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="http://10.0.0.1/imagem.png" TargetMode="External"/></Relationships>',
        ]))->assertStatus(422)->assertJsonValidationErrors('file');

        Process::assertNothingRan();
    }

    public function test_rejects_files_above_the_size_limit(): void
    {
        Process::fake();
        $this->actingAsRole('admin');

        $big = UploadedFile::fake()->create('contrato.docx', 11 * 1024, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');

        $this->upload($big)->assertStatus(422)->assertJsonValidationErrors('file');
    }

    // ------------------------------------------------ 8-11. falhas do conversor

    public function test_conversion_failure_keeps_the_draft_and_blocks_sending(): void
    {
        Process::fake(fn () => Process::result(errorOutput: 'Error: source file could not be loaded', exitCode: 1));
        $this->fakeClicksign();
        $this->actingAsRole('admin');

        $response = $this->upload($this->docx())->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.conversion.status', 'failed')
            ->assertJsonPath('data.signing_file.ready', false)
            ->assertJsonPath('data.abilities.send', false)
            ->assertJsonPath('data.abilities.retry_conversion', true)
            ->assertJsonPath('data.abilities.download_signing_pdf', false);

        // Mensagem amigável, sem caminho nem saída do processo.
        $this->assertStringContainsString('Não foi possível converter', $response->json('message'));
        $this->assertStringNotContainsString('source file could not be loaded', $response->getContent());

        $document = Document::findOrFail($response->json('data.id'));
        Storage::disk('local')->assertExists($document->original_file_path);
        $this->assertNull($document->signing_file_path);
        $this->assertDatabaseHas('document_events', ['document_id' => $document->id, 'event' => 'conversion_failed']);

        // 18. Conversão falhou → nenhum envelope é criado.
        $this->postJson("/api/documents/{$document->id}/send")->assertStatus(422);
        Http::assertNothingSent();
        $this->assertSame(DocumentStatus::Draft, $document->refresh()->status);

        $this->assertTempDirectoryIsEmpty();
    }

    public function test_conversion_timeout_is_reported_and_cleaned_up(): void
    {
        Process::fake(fn () => new ProcessTimedOutException(
            new SymfonyTimedOut(new SymfonyProcess(['soffice']), SymfonyTimedOut::TYPE_GENERAL),
            new ProcessResult(new SymfonyProcess(['soffice'])),
        ));
        $this->actingAsRole('admin');

        $response = $this->upload($this->docx())->assertCreated()->assertJsonPath('data.conversion.status', 'failed');

        $this->assertStringContainsString('demorou demais', $response->json('message'));
        $this->assertDatabaseHas('document_events', ['event' => 'conversion_failed']);
        $this->assertTempDirectoryIsEmpty();
    }

    public function test_missing_libreoffice_disables_word_but_pdf_keeps_working(): void
    {
        config(['services.document_converter.binary' => 'soffice-que-nao-existe-'.uniqid()]);
        Process::fake();
        $this->actingAsRole('admin');

        $response = $this->upload($this->docx())->assertCreated()->assertJsonPath('data.conversion.status', 'failed');
        $this->assertStringContainsString('não está disponível', $response->json('message'));
        Process::assertNothingRan();

        // PDF continua funcionando normalmente.
        $this->upload($this->pdf())->assertCreated()->assertJsonPath('data.signing_file.ready', true);
    }

    public function test_output_that_is_not_a_pdf_is_rejected_and_cleaned_up(): void
    {
        Process::fake(function (PendingProcess $process) {
            $outDir = $process->command[array_search('--outdir', $process->command, true) + 1];
            file_put_contents($outDir.DIRECTORY_SEPARATOR.'document.pdf', 'não sou pdf');

            return Process::result();
        });
        $this->actingAsRole('admin');

        $this->upload($this->docx())->assertCreated()->assertJsonPath('data.conversion.status', 'failed');
        $this->assertTempDirectoryIsEmpty();
    }

    public function test_temporary_files_are_removed_after_a_successful_conversion(): void
    {
        $this->fakeConverterSuccess();
        $this->actingAsRole('admin');

        $this->upload($this->docx())->assertCreated();

        $this->assertTempDirectoryIsEmpty();
    }

    public function test_failed_conversion_can_be_retried_without_reuploading(): void
    {
        config(['services.document_converter.binary' => 'soffice-que-nao-existe-'.uniqid()]);
        Process::fake();
        $this->actingAsRole('attendant');

        $id = $this->upload($this->docx())->assertCreated()->json('data.id');

        // "Instalaram o LibreOffice": nova tentativa funciona.
        config(['services.document_converter.binary' => PHP_BINARY]);
        $this->fakeConverterSuccess();

        $this->postJson("/api/documents/{$id}/convert")
            ->assertOk()
            ->assertJsonPath('data.conversion.status', 'converted')
            ->assertJsonPath('data.abilities.send', true);

        $this->postJson("/api/documents/{$id}/convert")->assertStatus(409);
    }

    // ------------------------------------------------ 14, 17. envio à Clicksign

    public function test_legacy_pdf_document_is_sent_exactly_as_before(): void
    {
        $this->fakeClicksign();
        $this->actingAsRole('admin');

        // Documento anterior a esta etapa: coluna nova com o default "pdf".
        $id = $this->upload($this->pdf('antigo.pdf', "%PDF-1.4 antigo\n%%EOF"))->json('data.id');
        $this->assertNull(Document::find($id)->conversion_status);

        $this->postJson("/api/documents/{$id}/send")->assertOk()->assertJsonPath('data.status', 'awaiting_signature');

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/documents')
            && $r['data']['attributes']['content_base64'] === 'data:application/pdf;base64,'.base64_encode("%PDF-1.4 antigo\n%%EOF"));
    }

    public function test_only_the_converted_pdf_is_sent_to_clicksign_never_the_docx(): void
    {
        $this->fakeConverterSuccess();
        $this->fakeClicksign();
        $this->actingAsRole('admin');

        $id = $this->upload($this->docx())->json('data.id');

        $this->postJson("/api/documents/{$id}/send")->assertOk();

        Http::assertSent(function (Request $r) {
            if (! str_ends_with($r->url(), '/documents')) {
                return false;
            }

            $content = $r['data']['attributes']['content_base64'];
            $prefix = 'data:application/pdf;base64,';

            return str_starts_with($content, $prefix)
                && base64_decode(substr($content, strlen($prefix))) === self::CONVERTED_PDF
                && str_ends_with($r['data']['attributes']['filename'], '.pdf');
        });

        // Nenhum byte do ZIP (Word) saiu para a Clicksign.
        Http::assertNotSent(fn (Request $r) => str_contains($r->body(), base64_encode('PK')) && str_ends_with($r->url(), '/documents')
            && str_starts_with(base64_decode(substr($r['data']['attributes']['content_base64'], strlen('data:application/pdf;base64,'))), 'PK'));
    }

    // ------------------------------------------------ 15, 16, 19. downloads e permissão

    public function test_downloads_return_the_right_file_for_each_action(): void
    {
        $this->fakeConverterSuccess();
        $this->actingAsRole('attendant');

        $id = $this->upload($this->docx())->json('data.id');
        $document = Document::findOrFail($id);

        // Baixar original = o DOCX, como foi enviado.
        $original = $this->get("/api/documents/{$id}/file")->assertOk();
        $this->assertSame(DocumentFileType::Docx->mimeType(), $original->headers->get('Content-Type'));
        $this->assertStringContainsString('.docx', $original->headers->get('Content-Disposition'));
        $this->assertSame(Storage::disk('local')->get($document->original_file_path), $original->streamedContent());

        // PDF para assinatura = o convertido.
        $signing = $this->get("/api/documents/{$id}/signing-file")->assertOk();
        $this->assertSame('application/pdf', $signing->headers->get('Content-Type'));
        $this->assertSame(self::CONVERTED_PDF, $signing->streamedContent());
    }

    public function test_signing_pdf_of_a_pdf_document_is_the_original_itself(): void
    {
        Process::fake();
        $this->actingAsRole('attendant');

        $id = $this->upload($this->pdf('contrato.pdf', "%PDF-1.4 original\n%%EOF"))->json('data.id');

        $this->assertSame("%PDF-1.4 original\n%%EOF", $this->get("/api/documents/{$id}/signing-file")->assertOk()->streamedContent());
    }

    public function test_users_without_permission_cannot_download_or_convert(): void
    {
        $this->fakeConverterSuccess();
        $this->actingAsRole('admin');
        $order = Order::factory()->create(['client_id' => $this->client->id]);
        $id = $this->upload($this->docx(), ['order_id' => $order->id])->json('data.id');

        foreach (['photographer', 'editor'] as $role) {
            $this->actingAsRole($role);

            $this->get("/api/documents/{$id}/file")->assertForbidden();
            $this->get("/api/documents/{$id}/signing-file")->assertForbidden();
            $this->postJson("/api/documents/{$id}/convert")->assertForbidden();
            $this->getJson("/api/documents/{$id}")
                ->assertJsonPath('data.abilities.download_original', false)
                ->assertJsonPath('data.abilities.download_signing_pdf', false);
        }
    }

    public function test_signing_file_is_unavailable_while_conversion_is_pending(): void
    {
        Process::fake(fn () => Process::result(exitCode: 1));
        $this->actingAsRole('admin');

        $id = $this->upload($this->docx())->json('data.id');

        $this->getJson("/api/documents/{$id}/signing-file")->assertStatus(409);
    }

    // ------------------------------------------------ troca de arquivo

    public function test_replacing_a_docx_with_a_pdf_removes_the_old_files(): void
    {
        $this->fakeConverterSuccess();
        $this->actingAsRole('admin');

        $id = $this->upload($this->docx())->json('data.id');
        $before = Document::findOrFail($id);

        $this->post("/api/documents/{$id}", ['_method' => 'PUT', 'file' => $this->pdf('novo.pdf')], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.file.type', 'pdf')
            ->assertJsonPath('data.conversion', null)
            ->assertJsonPath('data.signing_file.same_as_original', true);

        Storage::disk('local')->assertMissing([$before->original_file_path, $before->signing_file_path]);
        $after = Document::findOrFail($id);
        $this->assertNull($after->signing_file_path);
        $this->assertNull($after->conversion_status);
    }

    // ------------------------------------------------ 20. Pedidos e Agenda

    public function test_converted_word_documents_show_up_in_orders(): void
    {
        $this->fakeConverterSuccess();
        $this->actingAsRole('attendant');
        $order = Order::factory()->create(['client_id' => $this->client->id]);

        $id = $this->upload($this->docx(), ['order_id' => $order->id])->json('data.id');

        $this->getJson("/api/documents?order_id={$order->id}")
            ->assertOk()
            ->assertJsonPath('data.0.id', $id)
            ->assertJsonPath('data.0.file.type', 'docx')
            ->assertJsonPath('data.0.conversion.status', 'converted');
    }

    public function test_conversion_status_enum_is_persisted(): void
    {
        $this->fakeConverterSuccess();
        $this->actingAsRole('admin');

        $id = $this->upload($this->docx())->json('data.id');

        $this->assertSame(DocumentConversionStatus::Converted, Document::find($id)->conversion_status);
    }
}
