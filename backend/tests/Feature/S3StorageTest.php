<?php

namespace Tests\Feature;

use App\Enums\DocumentStatus;
use App\Enums\GalleryStatus;
use App\Models\Client;
use App\Models\Document;
use App\Models\Gallery;
use App\Models\GalleryImage;
use App\Models\User;
use App\Services\FileStorage;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Laravel\Sanctum\Sanctum;
use League\Flysystem\Config;
use League\Flysystem\FileAttributes;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemAdapter as FlysystemAdapter;
use League\Flysystem\UnableToCheckFileExistence;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToWriteFile;
use Tests\TestCase;

/**
 * Armazenamento persistente com SNAPMANAGER_FILESYSTEM_DISK=s3.
 *
 * Nenhum teste conecta à AWS: o disco "s3" é substituído por Storage::fake
 * (ou por um adaptador que simula o S3 fora do ar). O disco "local" também
 * é falso — e os testes conferem que NADA é gravado nele quando o disco
 * configurado é o S3 (a lógica não depende do filesystem local).
 */
class S3StorageTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Storage::fake('local');
        Storage::fake('public');
        Storage::fake('s3');

        config(['filesystems.snapmanager.disk' => 's3']);

        $this->client = Client::factory()->create(['name' => 'Maria Cliente']);
    }

    // ---------------------------------------------------------------- helpers

    private function actingAsRole(string $role): User
    {
        $user = User::factory()->create(['role' => $role]);
        Sanctum::actingAs($user);

        return $user;
    }

    private function s3(): FilesystemAdapter
    {
        return Storage::disk('s3');
    }

    private function gallery(array $attributes = []): Gallery
    {
        return Gallery::factory()->create([
            'client_id' => $this->client->id,
            'status' => GalleryStatus::Active->value,
            'expires_at' => now()->addWeek(),
            'watermark_enabled' => true,
            'watermark_type' => 'text',
            'watermark_text' => 'PROIBIDO REPRODUÇÃO',
            ...$attributes,
        ]);
    }

    private function upload(Gallery $gallery, int $width = 1200, int $height = 800): GalleryImage
    {
        $this->actingAsRole('admin');

        $id = $this->post("/api/galleries/{$gallery->id}/images", [
            'images' => [UploadedFile::fake()->image('Foto do Casamento.jpg', $width, $height)],
        ], ['Accept' => 'application/json'])->assertOk()->json('data.0.id');

        return GalleryImage::findOrFail($id);
    }

    private function assertNothingOnLocalDisk(): void
    {
        $this->assertSame([], Storage::disk('local')->allFiles(), 'Nada persistente deveria ir para o disco local quando o disco configurado é o S3.');
    }

    /** Disco "s3" que simula o S3 fora do ar (credencial inválida, rede...). */
    private function breakS3(): void
    {
        $adapter = new class implements FlysystemAdapter
        {
            private const REASON = 'Connection refused (X-Amz-Signature=segredo-da-url AKIAABCDEFGHIJKLMNOP)';

            public function fileExists(string $path): bool
            {
                throw UnableToCheckFileExistence::forLocation($path);
            }

            public function directoryExists(string $path): bool
            {
                throw UnableToCheckFileExistence::forLocation($path);
            }

            public function write(string $path, string $contents, Config $config): void
            {
                throw UnableToWriteFile::atLocation($path, self::REASON);
            }

            public function writeStream(string $path, $contents, Config $config): void
            {
                throw UnableToWriteFile::atLocation($path, self::REASON);
            }

            public function read(string $path): string
            {
                throw UnableToReadFile::fromLocation($path, self::REASON);
            }

            public function readStream(string $path)
            {
                throw UnableToReadFile::fromLocation($path, self::REASON);
            }

            public function delete(string $path): void {}

            public function deleteDirectory(string $path): void {}

            public function createDirectory(string $path, Config $config): void {}

            public function setVisibility(string $path, string $visibility): void {}

            public function visibility(string $path): FileAttributes
            {
                throw UnableToReadFile::fromLocation($path, self::REASON);
            }

            public function mimeType(string $path): FileAttributes
            {
                throw UnableToReadFile::fromLocation($path, self::REASON);
            }

            public function lastModified(string $path): FileAttributes
            {
                throw UnableToReadFile::fromLocation($path, self::REASON);
            }

            public function fileSize(string $path): FileAttributes
            {
                throw UnableToReadFile::fromLocation($path, self::REASON);
            }

            public function listContents(string $path, bool $deep): iterable
            {
                return [];
            }

            public function move(string $source, string $destination, Config $config): void {}

            public function copy(string $source, string $destination, Config $config): void {}
        };

        Storage::set('s3', new FilesystemAdapter(new Filesystem($adapter), $adapter));
    }

    // ------------------------------------------------------------- galerias

    public function test_upload_stores_original_thumbnail_and_watermarked_versions_on_s3(): void
    {
        $image = $this->upload($this->gallery());

        $this->assertMatchesRegularExpression("#^galleries/{$image->gallery_id}/originals/[0-9a-f-]{36}\\.jpg$#", $image->path);
        $this->assertSame("galleries/{$image->gallery_id}/thumbnails/{$image->stored_name}", $image->thumbnail_path);
        $this->assertSame("galleries/{$image->gallery_id}/watermarked/{$image->stored_name}", $image->watermarked_path);
        $this->assertSame("galleries/{$image->gallery_id}/watermarked-thumbnails/{$image->stored_name}", $image->watermarked_thumbnail_path);
        $this->assertStringNotContainsString('Casamento', $image->path, 'O nome enviado pelo cliente não vira object key.');

        $this->s3()->assertExists([$image->path, $image->thumbnail_path, $image->watermarked_path, $image->watermarked_thumbnail_path]);

        // Miniatura realmente reduzida (480 px) e marca d'água limitada a 2200 px.
        [$thumbWidth] = getimagesizefromstring($this->s3()->get($image->thumbnail_path));
        $this->assertSame(480, $thumbWidth);
        $this->assertNotSame($this->s3()->get($image->path), $this->s3()->get($image->watermarked_path));

        $this->assertNothingOnLocalDisk();
    }

    public function test_image_watermark_is_read_from_s3_and_applied(): void
    {
        $gallery = $this->gallery(['watermark_type' => 'image', 'watermark_text' => null]);
        $this->actingAsRole('admin');

        $this->post("/api/galleries/{$gallery->id}/watermark-image", [
            'watermark_image' => UploadedFile::fake()->image('logo.png', 300, 100),
        ], ['Accept' => 'application/json'])->assertOk();

        $gallery->refresh();
        $this->assertMatchesRegularExpression("#^galleries/{$gallery->id}/watermark-source/[0-9a-f-]{36}\\.png$#", $gallery->watermark_image_path);
        $this->s3()->assertExists($gallery->watermark_image_path);

        $image = $this->upload($gallery);

        $this->assertNotNull($image->watermarked_path);
        $this->s3()->assertExists($image->watermarked_path);
        $this->assertNothingOnLocalDisk();
    }

    public function test_team_reads_original_and_thumbnail_through_laravel(): void
    {
        $image = $this->upload($this->gallery());

        $original = $this->get("/api/galleries/{$image->gallery_id}/images/{$image->id}/file")->assertOk();
        $this->assertSame($this->s3()->get($image->path), $original->streamedContent());
        $this->assertSame('image/jpeg', $original->headers->get('Content-Type'));
        $this->assertStringContainsString('max-age', $original->headers->get('Cache-Control'));

        $thumbnail = $this->get("/api/galleries/{$image->gallery_id}/images/{$image->id}/thumbnail")->assertOk();
        $this->assertSame($this->s3()->get($image->thumbnail_path), $thumbnail->streamedContent());
    }

    public function test_internal_files_require_authentication(): void
    {
        $image = $this->upload($this->gallery());
        $this->app['auth']->forgetGuards();

        $this->getJson("/api/galleries/{$image->gallery_id}/images/{$image->id}/file")->assertUnauthorized();
        $this->getJson("/api/galleries/{$image->gallery_id}/images/{$image->id}/thumbnail")->assertUnauthorized();
    }

    public function test_public_gallery_never_serves_the_original_when_watermarked(): void
    {
        $image = $this->upload($this->gallery());
        $token = $image->gallery->access_token;

        $full = $this->get("/api/public/galleries/{$token}/images/{$image->id}")->assertOk()->streamedContent();
        $thumb = $this->get("/api/public/galleries/{$token}/images/{$image->id}/thumbnail")->assertOk()->streamedContent();

        $original = $this->s3()->get($image->path);
        $this->assertSame($this->s3()->get($image->watermarked_path), $full);
        $this->assertSame($this->s3()->get($image->watermarked_thumbnail_path), $thumb);
        $this->assertNotSame($original, $full);
        $this->assertNotSame($original, $thumb);

        // A resposta da galeria pública não expõe object keys nem URL do S3.
        $json = $this->getJson("/api/public/galleries/{$token}")->assertOk()->getContent();
        $this->assertStringNotContainsString('galleries/'.$image->gallery_id.'/', $json);
        $this->assertStringNotContainsString('amazonaws', $json);
    }

    public function test_public_gallery_without_watermark_serves_a_light_preview(): void
    {
        $gallery = $this->gallery(['watermark_enabled' => false, 'watermark_type' => 'none', 'watermark_text' => null]);
        $image = $this->upload($gallery, 2600, 1300);
        $this->assertNull($image->watermarked_path);

        $body = $this->get("/api/public/galleries/{$gallery->access_token}/images/{$image->id}")->assertOk()->streamedContent();

        [$width] = getimagesizefromstring($body);
        $this->assertSame(2000, $width);
        $this->s3()->assertExists("galleries/{$gallery->id}/previews/{$image->stored_name}");
    }

    public function test_missing_thumbnail_object_is_regenerated_from_the_original(): void
    {
        $image = $this->upload($this->gallery());
        $this->s3()->delete($image->thumbnail_path);

        $this->get("/api/galleries/{$image->gallery_id}/images/{$image->id}/thumbnail")->assertOk();

        $this->s3()->assertExists($image->fresh()->thumbnail_path);
    }

    public function test_old_image_without_thumbnail_gets_one_on_first_access(): void
    {
        $image = $this->upload($this->gallery());
        $this->s3()->delete($image->thumbnail_path);
        $image->forceFill(['thumbnail_path' => null])->save();

        $this->get("/api/galleries/{$image->gallery_id}/images/{$image->id}/thumbnail")->assertOk();

        $this->assertSame("galleries/{$image->gallery_id}/thumbnails/{$image->stored_name}", $image->fresh()->thumbnail_path);
        $this->s3()->assertExists($image->fresh()->thumbnail_path);
    }

    public function test_deleting_an_image_removes_every_version_from_s3(): void
    {
        $image = $this->upload($this->gallery());
        $keys = [$image->path, $image->thumbnail_path, $image->watermarked_path, $image->watermarked_thumbnail_path];

        $this->deleteJson("/api/galleries/{$image->gallery_id}/images/{$image->id}")->assertNoContent();

        $this->s3()->assertMissing($keys);
    }

    public function test_soft_deleting_a_gallery_keeps_its_files(): void
    {
        $image = $this->upload($this->gallery());

        $this->deleteJson("/api/galleries/{$image->gallery_id}")->assertNoContent();

        $this->s3()->assertExists([$image->path, $image->watermarked_path]);
    }

    // ------------------------------------------------------------ documentos

    private function pdf(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('Contrato Maria.pdf', "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\n%%EOF\n");
    }

    private function createPdfDocument(): Document
    {
        $this->actingAsRole('admin');

        $id = $this->post('/api/documents', [
            'title' => 'Contrato de ensaio',
            'type' => 'photoshoot',
            'client_id' => $this->client->id,
            'file' => $this->pdf(),
            'signers' => [['name' => 'Maria Cliente', 'channel' => 'email', 'email' => 'maria@example.com']],
        ], ['Accept' => 'application/json'])->assertCreated()->json('data.id');

        return Document::findOrFail($id);
    }

    public function test_pdf_document_is_stored_and_downloaded_from_s3(): void
    {
        $document = $this->createPdfDocument();

        $this->assertMatchesRegularExpression('#^documents/original/[0-9a-f-]{36}\.pdf$#', $document->original_file_path);
        $this->s3()->assertExists($document->original_file_path);
        $this->assertNothingOnLocalDisk();

        $response = $this->get("/api/documents/{$document->id}/file")->assertOk();
        $this->assertSame($this->s3()->get($document->original_file_path), $response->streamedContent());
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        // Nome amigável no download, mesmo com a object key em UUID.
        $this->assertStringContainsString('contrato-de-ensaio-original.pdf', $response->headers->get('Content-Disposition'));

        $this->get("/api/documents/{$document->id}/signing-file")->assertOk();
    }

    public function test_document_policy_still_applies_with_s3(): void
    {
        $document = $this->createPdfDocument();

        // Editor só vê documentos vinculados a pedido: este não é.
        $this->actingAsRole('editor');
        $this->get("/api/documents/{$document->id}/file")->assertForbidden();
        $this->get("/api/documents/{$document->id}/signing-file")->assertForbidden();
    }

    public function test_signed_pdf_from_clicksign_is_stored_on_s3(): void
    {
        config([
            'services.clicksign.base_url' => 'https://sandbox.clicksign.com/api/v3',
            'services.clicksign.access_token' => 'token-ficticio',
        ]);

        $document = $this->createPdfDocument();
        $document->forceFill([
            'status' => DocumentStatus::Signed,
            'signed_at' => now(),
            'clicksign_envelope_id' => '11111111-1111-4111-8111-111111111111',
            'clicksign_document_id' => '22222222-2222-4222-8222-222222222222',
        ])->save();

        Http::fake([
            'sandbox.clicksign.com/*' => Http::response(['data' => ['id' => 'x', 'links' => ['files' => ['signed' => 'https://clicksign-sandbox-content.s3.amazonaws.com/assinado.pdf?X-Amz-Signature=abc']]]]),
            'clicksign-sandbox-content.s3.amazonaws.com/*' => Http::response("%PDF-1.4\n% assinado\n%%EOF\n"),
        ]);

        $this->get("/api/documents/{$document->id}/download-signed")->assertOk();

        $document->refresh();
        $this->assertMatchesRegularExpression('#^documents/signed/[0-9a-f-]{36}\.pdf$#', $document->signed_file_path);
        $this->assertStringStartsWith('%PDF-', $this->s3()->get($document->signed_file_path));
        $this->assertNothingOnLocalDisk();
    }

    // -------------------------------------------------------- falhas e erros

    public function test_missing_object_returns_friendly_404_without_leaking_the_key(): void
    {
        $document = $this->createPdfDocument();
        $this->s3()->delete($document->original_file_path);

        $response = $this->getJson("/api/documents/{$document->id}/file")
            ->assertNotFound()
            ->assertJson(['message' => 'Arquivo não encontrado.']);

        $this->assertStringNotContainsString($document->original_file_path, $response->getContent());
    }

    public function test_unavailable_storage_returns_friendly_503_and_sanitized_log(): void
    {
        $image = $this->upload($this->gallery());
        $this->breakS3();
        Log::spy();

        $response = $this->getJson("/api/galleries/{$image->gallery_id}/images/{$image->id}/file")
            ->assertStatus(503)
            ->assertExactJson(['message' => 'Não foi possível carregar o arquivo. Tente novamente.']);

        $this->assertStringNotContainsString('galleries/', $response->getContent());

        Log::shouldHaveReceived('warning')->withArgs(function (string $message, array $context) use ($image) {
            $json = json_encode($context);

            return $message === 'Falha no armazenamento de arquivos.'
                && $context['operation'] === 'read'
                && $context['key'] === $image->path
                && ! str_contains($json, 'segredo-da-url')
                && ! str_contains($json, 'AKIAABCDEFGHIJKLMNOP');
        })->atLeast()->once();
    }

    public function test_upload_fails_cleanly_when_storage_is_unavailable(): void
    {
        $gallery = $this->gallery();
        $this->breakS3();

        $this->actingAsRole('admin');
        $this->post("/api/galleries/{$gallery->id}/images", [
            'images' => [UploadedFile::fake()->image('foto.jpg', 800, 600)],
        ], ['Accept' => 'application/json'])->assertStatus(503);

        $this->assertSame(0, $gallery->images()->count());
    }

    public function test_send_with_unavailable_storage_creates_no_envelope_and_keeps_draft(): void
    {
        config([
            'services.clicksign.base_url' => 'https://sandbox.clicksign.com/api/v3',
            'services.clicksign.access_token' => 'token-ficticio',
        ]);
        Http::fake();

        $document = $this->createPdfDocument();

        // O arquivo existe (a checagem passa), mas a LEITURA falha.
        $adapter = Storage::disk('s3');
        $broken = \Mockery::mock($adapter)->makePartial();
        $driver = \Mockery::mock($adapter->getDriver())->makePartial();
        $driver->shouldReceive('read')->andThrow(UnableToReadFile::fromLocation($document->original_file_path, 'timeout'));
        $broken->shouldReceive('getDriver')->andReturn($driver);
        Storage::set('s3', $broken);

        $this->postJson("/api/documents/{$document->id}/send")->assertStatus(503);

        Http::assertNothingSent();
        $this->assertSame(DocumentStatus::Draft, $document->fresh()->status);
    }

    // ------------------------------------------------------- fotos de perfil

    public function test_profile_photo_is_stored_on_s3_and_served_by_signed_url(): void
    {
        $user = $this->actingAsRole('attendant');

        $url = $this->postJson('/api/profile/photo', ['photo' => UploadedFile::fake()->image('eu.jpg', 200, 200)])
            ->assertOk()
            ->json('profile_photo_url');

        $user->refresh();
        $this->assertMatchesRegularExpression('#^profile-photos/[0-9a-f-]{36}\.jpg$#', $user->profile_photo_path);
        $this->s3()->assertExists($user->profile_photo_path);
        $this->assertStringContainsString('signature=', $url);
        $this->assertStringNotContainsString('amazonaws', $url);

        $this->app['auth']->forgetGuards();
        $path = parse_url($url, PHP_URL_PATH).'?'.parse_url($url, PHP_URL_QUERY);

        $this->get($path)->assertOk();
        // URL adulterada (outro usuário) ou sem assinatura: bloqueada.
        $this->get(str_replace("/profile-photos/{$user->id}", '/profile-photos/999', $path))->assertForbidden();
        $this->get("/api/files/profile-photos/{$user->id}")->assertForbidden();
    }

    public function test_legacy_profile_photo_on_public_disk_still_works(): void
    {
        $user = User::factory()->create(['profile_photo_path' => 'profile-photos/antiga.jpg']);
        Storage::disk('public')->put('profile-photos/antiga.jpg', 'foto-antiga');

        $url = $user->profile_photo_url;
        $path = parse_url($url, PHP_URL_PATH).'?'.parse_url($url, PHP_URL_QUERY);

        $this->assertSame('foto-antiga', $this->get($path)->assertOk()->streamedContent());
    }

    // ------------------------------------------------------------ segurança

    public function test_unsafe_object_keys_are_rejected(): void
    {
        foreach (['../.env', 'galleries/../../.env', '/etc/passwd', 'a\\b.jpg', '', "x\0.jpg", 'galleries/7/./x.jpg'] as $key) {
            try {
                FileStorage::assertSafeKey($key);
                $this->fail("Key insegura aceita: {$key}");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame('galleries/7/originals/a-b_c.jpg', FileStorage::assertSafeKey('galleries/7/originals/a-b_c.jpg'));
    }

    public function test_error_messages_are_sanitized_for_logs(): void
    {
        config(['filesystems.disks.s3.secret' => 'meu-secret-super-sigiloso']);

        $sanitized = FileStorage::sanitizeError(new \RuntimeException(
            'GET https://b.s3.amazonaws.com/x?X-Amz-Credential=AKIAABCDEFGHIJKLMNOP&X-Amz-Signature=deadbeef falhou com meu-secret-super-sigiloso'
        ));

        $this->assertStringNotContainsString('deadbeef', $sanitized);
        $this->assertStringNotContainsString('AKIAABCDEFGHIJKLMNOP', $sanitized);
        $this->assertStringNotContainsString('meu-secret-super-sigiloso', $sanitized);
    }

    /**
     * Arquitetura: a lógica de arquivos persistentes não usa caminho absoluto
     * do servidor. storage_path() só é aceito para a área TEMPORÁRIA da
     * conversão DOCX; ->path() (caminho absoluto de um disco) não é usado.
     */
    public function test_business_code_does_not_depend_on_local_absolute_paths(): void
    {
        $violations = [];

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path())) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $relative = str_replace(app_path().DIRECTORY_SEPARATOR, '', $file->getPathname());
            $code = file_get_contents($file->getPathname());

            if (preg_match('/->path\(|public_path\(|->storeAs\(|->store\(/', $code)) {
                $violations[] = $relative;
            }

            if (str_contains($code, 'storage_path(') && $relative !== 'Services'.DIRECTORY_SEPARATOR.'DocumentConversionService.php') {
                $violations[] = $relative.' (storage_path)';
            }
        }

        $this->assertSame([], $violations);
    }

    public function test_local_disk_remains_the_default_for_development(): void
    {
        config(['filesystems.snapmanager.disk' => 'local']);

        $image = $this->upload($this->gallery());

        Storage::disk('local')->assertExists([$image->path, $image->thumbnail_path, $image->watermarked_path]);
        $this->assertSame([], $this->s3()->allFiles());
    }
}
