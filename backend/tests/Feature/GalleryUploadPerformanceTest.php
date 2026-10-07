<?php

namespace Tests\Feature;

use App\Exceptions\StorageUnavailableException;
use App\Services\FileStorage;
use App\Services\WatermarkService;
use Aws\CommandInterface;
use Aws\MockHandler;
use Aws\Result;
use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;
use Illuminate\Filesystem\AwsS3V3Adapter as LaravelS3Adapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter;
use League\Flysystem\Filesystem;
use Tests\TestCase;

/**
 * Desempenho do upload de fotos (04/10/2026).
 *
 * 1) Todas as versões saem de UMA decodificação do original
 *    (WatermarkService::galleryVariants).
 * 2) No S3, original + versões são gravados EM PARALELO
 *    (FileStorage::putMany com PutObject assíncrono).
 *
 * O S3 é o SDK oficial da AWS com MockHandler: nenhuma requisição sai para
 * a rede, mas o código exercitado é o mesmo da produção.
 */
class GalleryUploadPerformanceTest extends TestCase
{
    /** @var list<CommandInterface> */
    private array $commands = [];

    private function photo(int $width, int $height): string
    {
        return UploadedFile::fake()->image('foto.jpg', $width, $height)->get();
    }

    private function logo(): string
    {
        return UploadedFile::fake()->image('logo.png', 300, 100)->get();
    }

    /** Disco "s3" real (adaptadores do Laravel/Flysystem + SDK da AWS), com respostas simuladas. */
    private function fakeS3(callable ...$responses): MockHandler
    {
        $mock = new MockHandler;

        foreach ($responses as $response) {
            $mock->append(function (CommandInterface $command) use ($response) {
                $this->commands[] = $command;

                return $response($command);
            });
        }

        $client = new S3Client([
            'region' => 'us-east-2',
            'version' => 'latest',
            'credentials' => ['key' => 'AKIAFAKEFAKEFAKEFAKE', 'secret' => 'segredo-falso'],
            'handler' => $mock,
        ]);

        $adapter = new AwsS3V3Adapter($client, 'bucket-teste');
        Storage::set('s3', new LaravelS3Adapter(new Filesystem($adapter), $adapter, ['bucket' => 'bucket-teste'], $client));

        config([
            'filesystems.snapmanager.disk' => 's3',
            'filesystems.disks.s3.bucket' => 'bucket-teste',
            'filesystems.disks.s3.root' => '',
        ]);

        return $mock;
    }

    // ------------------------------------------------- processamento único

    public function test_variants_are_generated_from_a_single_decode_with_correct_sizes(): void
    {
        $variants = app(WatermarkService::class)->galleryVariants($this->photo(3000, 2000), 'jpg', 480, watermarkText: 'PROIBIDO REPRODUÇÃO');

        [$thumbWidth, $thumbHeight] = getimagesizefromstring($variants['thumbnail']);
        [$wmWidth, $wmHeight] = getimagesizefromstring($variants['watermarked']);
        [$wmThumbWidth] = getimagesizefromstring($variants['watermarked_thumbnail']);

        $this->assertSame([480, 320], [$thumbWidth, $thumbHeight]);
        $this->assertSame([2200, 1467], [$wmWidth, $wmHeight]);
        $this->assertSame(480, $wmThumbWidth);
        // A miniatura sem marca é tirada ANTES da marca d'água ser aplicada.
        $this->assertNotSame($variants['thumbnail'], $variants['watermarked_thumbnail']);
    }

    public function test_variants_with_image_watermark(): void
    {
        $variants = app(WatermarkService::class)->galleryVariants($this->photo(1200, 800), 'jpg', 480, watermarkImage: $this->logo());

        $this->assertNotNull($variants['watermarked']);
        $this->assertNotNull($variants['watermarked_thumbnail']);
        // Foto menor que 2200 px não é ampliada.
        $this->assertSame(1200, getimagesizefromstring($variants['watermarked'])[0]);
    }

    public function test_variants_without_watermark_only_create_the_thumbnail(): void
    {
        $variants = app(WatermarkService::class)->galleryVariants($this->photo(1200, 800), 'png', 480);

        $this->assertNotNull($variants['thumbnail']);
        $this->assertNull($variants['watermarked']);
        $this->assertNull($variants['watermarked_thumbnail']);
        $this->assertSame(IMAGETYPE_PNG, getimagesizefromstring($variants['thumbnail'])[2]);
    }

    public function test_invalid_image_returns_no_variants_instead_of_breaking_the_upload(): void
    {
        $variants = app(WatermarkService::class)->galleryVariants('isto não é uma imagem', 'jpg', 480, watermarkText: 'X');

        $this->assertSame(['thumbnail' => null, 'watermarked' => null, 'watermarked_thumbnail' => null], $variants);
    }

    // ------------------------------------------------ gravação em paralelo

    public function test_put_many_sends_every_object_to_s3_with_content_type_and_no_acl(): void
    {
        $ok = fn () => new Result(['ETag' => '"x"']);
        $this->fakeS3($ok, $ok, $ok);

        $original = UploadedFile::fake()->image('original.jpg', 800, 600);

        app(FileStorage::class)->putMany([
            ['key' => 'galleries/7/originals/a.jpg', 'contents' => $original, 'mime' => 'image/jpeg'],
            ['key' => 'galleries/7/watermarked/a.jpg', 'contents' => 'com-marca', 'mime' => 'image/jpeg'],
            ['key' => 'galleries/7/thumbnails/a.jpg', 'contents' => 'mini'],
        ]);

        $this->assertCount(3, $this->commands);

        foreach ($this->commands as $command) {
            $this->assertSame('PutObject', $command->getName());
            $this->assertSame('bucket-teste', $command['Bucket']);
            $this->assertSame('image/jpeg', $command['ContentType']);
            $this->assertArrayNotHasKey('ACL', $command->toArray(), 'Bucket privado com ACLs desativadas: nenhuma ACL é enviada.');
            $this->assertStringStartsWith('galleries/7/', $command['Key']);
        }

        $this->assertEqualsCanonicalizing(
            ['galleries/7/originals/a.jpg', 'galleries/7/watermarked/a.jpg', 'galleries/7/thumbnails/a.jpg'],
            array_map(fn (CommandInterface $c) => $c['Key'], $this->commands),
        );
    }

    public function test_put_many_waits_for_all_and_reports_failures_without_leaking_secrets(): void
    {
        $ok = fn () => new Result(['ETag' => '"x"']);
        $denied = fn (CommandInterface $command) => new S3Exception('Access Denied (Credential=AKIAFAKEFAKEFAKEFAKE/20261004)', $command, ['code' => 'AccessDenied']);
        $this->fakeS3($ok, $denied, $ok);
        Log::spy();

        try {
            app(FileStorage::class)->putMany([
                ['key' => 'galleries/7/originals/a.jpg', 'contents' => 'a'],
                ['key' => 'galleries/7/watermarked/a.jpg', 'contents' => 'b'],
                ['key' => 'galleries/7/thumbnails/a.jpg', 'contents' => 'c'],
            ]);
            $this->fail('A falha de uma das gravações deveria ser lançada.');
        } catch (StorageUnavailableException) {
            // Todas as gravações foram tentadas antes de reportar a falha.
            $this->assertCount(3, $this->commands);
        }

        Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context) => $message === 'Falha no armazenamento de arquivos.'
            && $context['operation'] === 'write'
            && $context['key'] === 'galleries/7/watermarked/a.jpg'
            && ! str_contains(json_encode($context), 'AKIAFAKEFAKEFAKEFAKE'))->once();
    }

    public function test_put_many_on_non_s3_disks_writes_sequentially_with_the_same_result(): void
    {
        Storage::fake('local');
        config(['filesystems.snapmanager.disk' => 'local']);

        app(FileStorage::class)->putMany([
            ['key' => 'galleries/7/originals/a.jpg', 'contents' => UploadedFile::fake()->createWithContent('a.jpg', 'original')],
            ['key' => 'galleries/7/thumbnails/a.jpg', 'contents' => 'mini'],
        ]);

        $this->assertSame('original', Storage::disk('local')->get('galleries/7/originals/a.jpg'));
        $this->assertSame('mini', Storage::disk('local')->get('galleries/7/thumbnails/a.jpg'));
    }
}
