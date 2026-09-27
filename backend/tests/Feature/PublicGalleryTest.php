<?php

namespace Tests\Feature;

use App\Enums\GalleryStatus;
use App\Models\Gallery;
use App\Models\GalleryImage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Galeria pública (link por token) — as regras que o SnapSwipe reaproveita.
 *
 * O SnapSwipe é só outra forma de escolher as MESMAS fotos: a seleção
 * continua em gallery_images.is_selected e é gravada pelo mesmo endpoint de
 * confirmação. Estes testes fixam esse contrato, que antes não tinha
 * cobertura automatizada.
 */
class PublicGalleryTest extends TestCase
{
    use RefreshDatabase;

    private Gallery $gallery;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->gallery = Gallery::factory()->create([
            'status' => GalleryStatus::Active->value,
            'expires_at' => now()->addWeek(),
        ]);
    }

    /** @return array<int, GalleryImage> */
    private function addImages(int $count, ?Gallery $gallery = null): array
    {
        $gallery ??= $this->gallery;
        $images = [];

        for ($i = 1; $i <= $count; $i++) {
            $path = "galleries/{$gallery->id}/foto-{$i}.jpg";
            Storage::disk('local')->put($path, 'bytes-da-foto');
            Storage::disk('local')->put("galleries/{$gallery->id}/wm-foto-{$i}.jpg", 'com-marca-dagua');
            Storage::disk('local')->put("galleries/{$gallery->id}/thumb-foto-{$i}.jpg", 'miniatura-com-marca');

            $images[] = $gallery->images()->create([
                'original_name' => "foto-{$i}.jpg",
                'stored_name' => "foto-{$i}.jpg",
                'path' => $path,
                'watermarked_path' => "galleries/{$gallery->id}/wm-foto-{$i}.jpg",
                'watermarked_thumbnail_path' => "galleries/{$gallery->id}/thumb-foto-{$i}.jpg",
                'mime_type' => 'image/jpeg',
                'size' => 1000,
                'sort_order' => $i,
            ]);
        }

        return $images;
    }

    private function url(?string $token = null): string
    {
        return '/api/public/galleries/'.($token ?? $this->gallery->access_token);
    }

    /** @param  array<int, int>  $ids */
    private function confirm(array $ids, ?string $token = null): \Illuminate\Testing\TestResponse
    {
        return $this->postJson($this->url($token).'/select', ['image_ids' => $ids]);
    }

    // ------------------------------------------------ acesso pelo token

    public function test_gallery_is_served_by_public_token_with_the_same_photos_for_both_modes(): void
    {
        $images = $this->addImages(3);

        $response = $this->getJson($this->url())->assertOk();

        // Os dois modos (grade e SnapSwipe) leem esta mesma lista.
        $response->assertJsonCount(3, 'data.images')
            ->assertJsonPath('data.images.0.id', $images[0]->id)
            ->assertJsonPath('data.images.0.is_selected', false)
            ->assertJsonPath('data.is_accessible', true);

        // Miniatura para os cartões e versão cheia para o "Ampliar".
        $this->assertStringEndsWith("/images/{$images[0]->id}/thumbnail", $response->json('data.images.0.thumbnail_url'));
        $this->assertStringEndsWith("/images/{$images[0]->id}", $response->json('data.images.0.url'));

        // Nada de caminho interno do servidor na resposta pública.
        $this->assertStringNotContainsString('galleries/'.$this->gallery->id.'/', $response->getContent());
    }

    public function test_invalid_token_is_rejected(): void
    {
        $this->addImages(1);

        $this->getJson($this->url('token-que-nao-existe'))->assertNotFound();
        $this->confirm([], 'token-que-nao-existe')->assertNotFound();
    }

    public function test_empty_gallery_is_served_without_error(): void
    {
        $this->getJson($this->url())->assertOk()->assertJsonCount(0, 'data.images');
    }

    // ------------------------------------------------ seleção compartilhada

    public function test_selection_is_persisted_and_shared_between_both_modes(): void
    {
        $images = $this->addImages(4);

        // Decisões tomadas no SnapSwipe chegam pelo mesmo endpoint da grade.
        $this->confirm([$images[0]->id, $images[2]->id])
            ->assertOk()
            ->assertJsonPath('data.images.0.is_selected', true)
            ->assertJsonPath('data.images.1.is_selected', false)
            ->assertJsonPath('data.images.2.is_selected', true);

        $this->assertDatabaseHas('gallery_images', ['id' => $images[0]->id, 'is_selected' => true]);
        $this->assertNotNull($images[0]->fresh()->selected_at);

        // Ao reabrir o link (em qualquer modo), a seleção volta marcada.
        $reopened = $this->getJson($this->url())->assertOk();
        $this->assertSame(
            [$images[0]->id, $images[2]->id],
            collect($reopened->json('data.images'))->where('is_selected', true)->pluck('id')->all(),
        );
    }

    public function test_deselecting_in_one_mode_is_reflected_for_the_other(): void
    {
        $images = $this->addImages(3);

        $this->confirm([$images[0]->id, $images[1]->id])->assertOk();

        // "Passar" numa foto antes selecionada = tirar da seleção.
        $this->confirm([$images[1]->id])->assertOk();

        $this->assertDatabaseHas('gallery_images', ['id' => $images[0]->id, 'is_selected' => false]);
        $this->assertNull($images[0]->fresh()->selected_at);
        $this->assertDatabaseHas('gallery_images', ['id' => $images[1]->id, 'is_selected' => true]);
    }

    public function test_undo_restores_the_previous_selection_state(): void
    {
        $images = $this->addImages(3);

        // Selecionou a foto por engano...
        $this->confirm([$images[0]->id, $images[1]->id])->assertOk();
        // ...desfez e confirmou de novo sem ela: só aquela foto muda.
        $this->confirm([$images[1]->id])->assertOk();

        $this->assertFalse($images[0]->fresh()->is_selected);
        $this->assertTrue($images[1]->fresh()->is_selected);
        $this->assertFalse($images[2]->fresh()->is_selected);
    }

    public function test_skipping_a_photo_never_deletes_it(): void
    {
        $images = $this->addImages(3);

        // Passou por todas sem selecionar nenhuma.
        $this->confirm([])->assertOk()->assertJsonCount(3, 'data.images');

        // As fotos e os arquivos continuam lá — passar não é apagar.
        $this->assertSame(3, $this->gallery->images()->count());
        foreach ($images as $image) {
            $this->assertFalse($image->fresh()->is_selected);
            Storage::disk('local')->assertExists([$image->path, $image->watermarked_path]);
        }
    }

    public function test_many_photos_can_be_selected_in_one_confirmation(): void
    {
        $images = $this->addImages(30);
        $ids = collect($images)->pluck('id')->all();

        $this->confirm($ids)->assertOk();

        $this->assertSame(30, $this->gallery->images()->where('is_selected', true)->count());
    }

    // ------------------------------------------------ segurança

    public function test_photo_from_another_gallery_cannot_be_selected_through_this_token(): void
    {
        $mine = $this->addImages(1);
        $otherGallery = Gallery::factory()->create(['status' => GalleryStatus::Active->value]);
        $stranger = $this->addImages(1, $otherGallery)[0];

        $this->confirm([$mine[0]->id, $stranger->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('image_ids.1');

        // Nada foi gravado — nem a foto válida da mesma requisição.
        $this->assertFalse($mine[0]->fresh()->is_selected);
        $this->assertFalse($stranger->fresh()->is_selected);
    }

    public function test_photo_files_of_another_gallery_are_not_served_by_this_token(): void
    {
        $this->addImages(1);
        $otherGallery = Gallery::factory()->create(['status' => GalleryStatus::Active->value]);
        $stranger = $this->addImages(1, $otherGallery)[0];

        $this->get($this->url()."/images/{$stranger->id}")->assertNotFound();
        $this->get($this->url()."/images/{$stranger->id}/thumbnail")->assertNotFound();
    }

    public function test_public_files_keep_the_watermarked_versions(): void
    {
        $image = $this->addImages(1)[0];

        // Versão cheia (usada pelo "Ampliar") e miniatura (cartões/grade):
        // as duas com marca d'água, nunca o arquivo original.
        $this->assertSame('com-marca-dagua', $this->get($this->url()."/images/{$image->id}")->assertOk()->streamedContent());
        $this->assertSame('miniatura-com-marca', $this->get($this->url()."/images/{$image->id}/thumbnail")->assertOk()->streamedContent());
    }

    // ------------------------------------------------ estados da galeria

    public function test_expired_gallery_blocks_new_selections(): void
    {
        $images = $this->addImages(2);
        $this->gallery->update(['expires_at' => now()->subDay()]);

        $this->getJson($this->url())->assertOk()->assertJsonPath('data.is_accessible', false);
        $this->confirm([$images[0]->id])->assertStatus(409);

        $this->assertFalse($images[0]->fresh()->is_selected);
    }

    public function test_closed_gallery_blocks_new_selections(): void
    {
        $images = $this->addImages(2);
        $this->gallery->update(['status' => GalleryStatus::Closed->value, 'closed_at' => now()]);

        $this->confirm([$images[0]->id])->assertStatus(409);
        $this->assertFalse($images[0]->fresh()->is_selected);
    }

    public function test_finishing_requires_an_explicit_confirmation(): void
    {
        $images = $this->addImages(3);

        // Navegar pela galeria (em qualquer modo) não finaliza nada.
        $this->getJson($this->url())->assertOk();
        $this->get($this->url()."/images/{$images[0]->id}/thumbnail")->assertOk();

        $this->assertNull($this->gallery->fresh()->selection_completed_at);

        // Só a confirmação marca a seleção como concluída.
        $this->confirm([$images[0]->id])->assertOk();
        $this->assertNotNull($this->gallery->fresh()->selection_completed_at);
    }

    public function test_client_can_change_the_selection_after_confirming_while_the_gallery_is_active(): void
    {
        $images = $this->addImages(3);

        $this->confirm([$images[0]->id])->assertOk();
        $firstConfirmation = $this->gallery->fresh()->selection_completed_at;

        $this->travel(1)->minute();
        $this->confirm([$images[1]->id, $images[2]->id])->assertOk();

        $gallery = $this->gallery->fresh();
        $this->assertTrue($gallery->selection_completed_at->gt($firstConfirmation));
        $this->assertFalse($images[0]->fresh()->is_selected);
        $this->assertTrue($images[1]->fresh()->is_selected);
    }
}
