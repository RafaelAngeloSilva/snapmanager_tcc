<?php

namespace Tests\Feature;

use App\Enums\GalleryStatus;
use App\Models\Gallery;
use App\Models\User;
use App\Support\ExecutionTimeBudget;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use League\Flysystem\UnableToWriteFile;
use Mockery;
use Tests\TestCase;

/**
 * Regressão do upload de galerias com o armazenamento no S3.
 *
 * Problema real (04/10/2026): enviar 15 fotos de uma vez falhava com
 * "Maximum execution time of 60 seconds exceeded" — o lote inteiro era
 * processado numa requisição só e todas as fotos (original + 3 versões
 * enviadas ao S3, cada) dividiam o MESMO limite de tempo do PHP. Agora:
 *   - cada foto renova o próprio orçamento de tempo (ExecutionTimeBudget);
 *   - o front-end envia uma foto por requisição;
 *   - uma falha no meio não deixa registro sem arquivo nem objeto órfão.
 *
 * Sem AWS real: o disco "s3" é Storage::fake.
 */
class GalleryUploadReliabilityTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<int> orçamentos de tempo pedidos durante a requisição */
    private array $budgets = [];

    private Gallery $gallery;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('s3');
        config(['filesystems.snapmanager.disk' => 's3']);

        // Espião: registra cada renovação do limite de tempo sem mexer no PHP.
        $this->app->instance(ExecutionTimeBudget::class, new class($this->budgets) extends ExecutionTimeBudget
        {
            public function __construct(private array &$calls) {}

            public function renew(int $seconds): void
            {
                $this->calls[] = $seconds;
            }
        });

        $this->gallery = Gallery::factory()->create([
            'status' => GalleryStatus::Active->value,
            'expires_at' => now()->addWeek(),
            'watermark_enabled' => true,
            'watermark_type' => 'text',
            'watermark_text' => 'PROIBIDO REPRODUÇÃO',
        ]);

        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
    }

    /** @return list<UploadedFile> */
    private function photos(int $count): array
    {
        return array_map(fn (int $i) => UploadedFile::fake()->image("foto-{$i}.jpg", 900, 600), range(1, $count));
    }

    private function upload(array $files)
    {
        return $this->post("/api/galleries/{$this->gallery->id}/images", ['images' => $files], ['Accept' => 'application/json']);
    }

    private function assertKeysInsideGalleryPrefix(): void
    {
        foreach (Storage::disk('s3')->allFiles() as $key) {
            $this->assertMatchesRegularExpression(
                "#^galleries/{$this->gallery->id}/(originals|thumbnails|watermarked|watermarked-thumbnails)/[0-9a-f-]{36}\\.jpg$#",
                $key,
                'Toda object key de foto precisa ficar dentro de galleries/ (prefixo permitido pela política IAM).'
            );
        }
    }

    public function test_single_photo_creates_original_thumbnail_and_watermarked_versions(): void
    {
        $this->upload($this->photos(1))->assertOk()->assertJsonCount(1, 'data');

        $image = $this->gallery->images()->sole();
        Storage::disk('s3')->assertExists([$image->path, $image->thumbnail_path, $image->watermarked_path, $image->watermarked_thumbnail_path]);
        $this->assertCount(4, Storage::disk('s3')->allFiles());
        $this->assertKeysInsideGalleryPrefix();
    }

    /** Regressão: o lote de 15 fotos que estourava o tempo de execução. */
    public function test_fifteen_photos_in_one_request_each_get_their_own_time_budget(): void
    {
        $this->upload($this->photos(15))->assertOk()->assertJsonCount(15, 'data');

        $this->assertSame(15, $this->gallery->images()->count());
        $this->assertCount(60, Storage::disk('s3')->allFiles());
        $this->assertKeysInsideGalleryPrefix();

        // O limite do PHP é renovado no início de CADA foto — as 15 não
        // dividem mais um único orçamento de tempo.
        $this->assertSame(array_fill(0, 15, 120), $this->budgets);
    }

    /** Fluxo do front-end: uma foto por requisição, em sequência. */
    public function test_photos_sent_one_per_request_accumulate_in_the_gallery(): void
    {
        foreach ($this->photos(3) as $photo) {
            $this->upload([$photo])->assertOk()->assertJsonCount(1, 'data');
        }

        $this->assertSame(3, $this->gallery->images()->count());
        $this->assertSame([1, 2, 3], $this->gallery->images()->orderBy('sort_order')->pluck('sort_order')->all());
        $this->assertCount(12, Storage::disk('s3')->allFiles());
    }

    public function test_failure_in_the_middle_removes_written_objects_and_creates_no_records(): void
    {
        // Foto que já estava na galeria: não pode ser afetada pela falha.
        $this->upload($this->photos(1))->assertOk();
        $existing = Storage::disk('s3')->allFiles();
        $this->assertCount(4, $existing);

        // A 10ª gravação deste upload falha (3ª foto, 2ª versão) — como uma
        // queda de rede ou permissão negada no S3 no meio do lote.
        $disk = Storage::disk('s3');
        $real = $disk->getDriver();
        $writes = 0;
        $failingWrite = function (string $method) use (&$writes, $real) {
            return function (string $path, $contents, array $config = []) use (&$writes, $real, $method) {
                if (++$writes === 10) {
                    throw UnableToWriteFile::atLocation($path, 'Connection reset');
                }

                $real->{$method}($path, $contents, $config);
            };
        };

        $driver = Mockery::mock($real)->makePartial();
        $driver->shouldReceive('write')->andReturnUsing($failingWrite('write'));
        $driver->shouldReceive('writeStream')->andReturnUsing($failingWrite('writeStream'));
        $broken = Mockery::mock($disk)->makePartial();
        $broken->shouldReceive('getDriver')->andReturn($driver);
        Storage::set('s3', $broken);

        $this->upload($this->photos(3))
            ->assertStatus(503)
            ->assertExactJson(['message' => 'Não foi possível carregar o arquivo. Tente novamente.']);

        // Nenhum registro novo e nenhum objeto órfão: sobra só a foto antiga.
        $this->assertSame(1, $this->gallery->images()->count());
        $this->assertEqualsCanonicalizing($existing, $disk->allFiles());
    }

    public function test_storage_healthcheck_writes_reads_and_deletes_without_exposing_credentials(): void
    {
        config([
            'filesystems.disks.s3.region' => 'us-east-2',
            'filesystems.disks.s3.bucket' => 'bucket-de-teste',
            'filesystems.disks.s3.key' => 'AKIAFAKEFAKEFAKEFAKE',
            'filesystems.disks.s3.secret' => 'segredo-que-nao-pode-aparecer',
        ]);

        $this->artisan('storage:healthcheck', ['--size' => 1])
            ->expectsOutputToContain('Disco ativo (SNAPMANAGER_FILESYSTEM_DISK): s3')
            ->expectsOutputToContain('Região: us-east-2')
            ->expectsOutputToContain('Bucket: bucket-de-teste')
            ->expectsOutputToContain('galleries/_healthcheck/')
            ->expectsOutputToContain('Armazenamento OK')
            ->doesntExpectOutputToContain('AKIAFAKEFAKEFAKEFAKE')
            ->doesntExpectOutputToContain('segredo-que-nao-pode-aparecer')
            ->assertSuccessful();

        // O objeto de teste é apagado no final.
        $this->assertSame([], Storage::disk('s3')->allFiles());
    }

    public function test_storage_healthcheck_reports_failure(): void
    {
        $disk = Storage::disk('s3');
        $driver = Mockery::mock($disk->getDriver())->makePartial();
        $driver->shouldReceive('write')->andThrow(UnableToWriteFile::atLocation('x', 'AccessDenied'));
        $broken = Mockery::mock($disk)->makePartial();
        $broken->shouldReceive('getDriver')->andReturn($driver);
        Storage::set('s3', $broken);

        $this->artisan('storage:healthcheck')
            ->expectsOutputToContain('FALHOU')
            ->assertFailed();
    }
}
