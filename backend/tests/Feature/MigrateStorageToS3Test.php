<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\Gallery;
use App\Models\GalleryImage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * php artisan storage:migrate-to-s3 — sem AWS real: "local", "public" e
 * "s3" são discos falsos (Storage::fake).
 */
class MigrateStorageToS3Test extends TestCase
{
    use RefreshDatabase;

    private GalleryImage $image;

    private Document $document;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('public');
        Storage::fake('s3');

        $gallery = Gallery::factory()->create();
        $local = Storage::disk('local');

        $local->put("galleries/{$gallery->id}/foto.jpg", 'original');
        $local->put("galleries/{$gallery->id}/thumbnails/foto.jpg", 'mini');
        $local->put("galleries/{$gallery->id}/watermarked/foto.jpg", 'com-marca');
        $local->put("galleries/{$gallery->id}/previews/foto.jpg", 'previa');

        $this->image = $gallery->images()->create([
            'original_name' => 'foto.jpg',
            'stored_name' => 'foto.jpg',
            'path' => "galleries/{$gallery->id}/foto.jpg",
            'thumbnail_path' => "galleries/{$gallery->id}/thumbnails/foto.jpg",
            'watermarked_path' => "galleries/{$gallery->id}/watermarked/foto.jpg",
            'mime_type' => 'image/jpeg',
            'size' => 8,
            'sort_order' => 1,
        ]);

        $local->put('documents/contrato.pdf', "%PDF-1.4\n%%EOF");
        $this->document = Document::withoutEvents(fn () => Document::forceCreate([
            'client_id' => $gallery->client_id,
            'title' => 'Contrato',
            'type' => 'photoshoot',
            'status' => 'draft',
            'original_file_path' => 'documents/contrato.pdf',
            'original_file_name' => 'contrato.pdf',
            'original_file_size' => 15,
            'original_file_type' => 'pdf',
        ]));

        // Foto de perfil antiga, no disco "public".
        Storage::disk('public')->put('profile-photos/eu.jpg', 'avatar');
        $this->user = User::factory()->create(['profile_photo_path' => 'profile-photos/eu.jpg']);
    }

    /** @return list<string> */
    private function expectedKeys(): array
    {
        $g = $this->image->gallery_id;

        return [
            "galleries/{$g}/foto.jpg",
            "galleries/{$g}/thumbnails/foto.jpg",
            "galleries/{$g}/watermarked/foto.jpg",
            "galleries/{$g}/previews/foto.jpg",
            'documents/contrato.pdf',
            'profile-photos/eu.jpg',
        ];
    }

    public function test_dry_run_is_the_default_and_sends_nothing(): void
    {
        $this->artisan('storage:migrate-to-s3')
            ->expectsOutputToContain('SIMULAÇÃO')
            ->expectsOutputToContain('documents/contrato.pdf')
            ->assertSuccessful();

        $this->assertSame([], Storage::disk('s3')->allFiles());
    }

    public function test_apply_copies_every_referenced_file_with_the_same_keys(): void
    {
        $this->artisan('storage:migrate-to-s3', ['--apply' => true])->assertSuccessful();

        Storage::disk('s3')->assertExists($this->expectedKeys());
        $this->assertSame('original', Storage::disk('s3')->get($this->image->path));
        $this->assertSame('avatar', Storage::disk('s3')->get('profile-photos/eu.jpg'));

        // Origem intacta e banco inalterado (as keys continuam as mesmas).
        Storage::disk('local')->assertExists($this->image->path);
        Storage::disk('public')->assertExists('profile-photos/eu.jpg');
        $this->assertSame('documents/contrato.pdf', $this->document->fresh()->original_file_path);
    }

    public function test_running_again_is_idempotent(): void
    {
        $this->artisan('storage:migrate-to-s3', ['--apply' => true])->assertSuccessful();
        $count = count(Storage::disk('s3')->allFiles());

        $this->artisan('storage:migrate-to-s3', ['--apply' => true])
            ->expectsTable(['Situação', 'Quantidade'], [
                ['Arquivos referenciados', 6],
                ['Enviados agora', 0],
                ['Já no destino', 6],
                ['Conflitos', 0],
                ['Ausentes', 0],
                ['Erros', 0],
            ])
            ->assertSuccessful();

        $this->assertCount($count, Storage::disk('s3')->allFiles());
    }

    public function test_conflicts_are_reported_and_never_overwritten(): void
    {
        Storage::disk('s3')->put($this->image->path, 'outro conteúdo, tamanho diferente');

        $this->artisan('storage:migrate-to-s3', ['--apply' => true])
            ->expectsOutputToContain('Conflitos')
            ->assertSuccessful();

        $this->assertSame('outro conteúdo, tamanho diferente', Storage::disk('s3')->get($this->image->path));
    }

    public function test_missing_source_files_are_reported(): void
    {
        Storage::disk('local')->delete('documents/contrato.pdf');

        $this->artisan('storage:migrate-to-s3')
            ->expectsOutputToContain('Ausentes na origem')
            ->expectsOutputToContain("documents/contrato.pdf  (documento #{$this->document->id}: original)")
            ->assertSuccessful();
    }

    public function test_refuses_a_target_that_is_not_s3(): void
    {
        $this->artisan('storage:migrate-to-s3', ['--to' => 'public', '--apply' => true])->assertFailed();
        $this->artisan('storage:migrate-to-s3', ['--from' => 's3', '--to' => 's3'])->assertFailed();

        $this->assertSame([], Storage::disk('s3')->allFiles());
    }
}
