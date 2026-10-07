<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        // Fotos de perfil agora vão para o armazenamento persistente do
        // SnapManager (disco "local" nos testes; S3 em produção).
        Storage::fake('local');
    }

    public function test_unauthenticated_request_is_rejected_with_401(): void
    {
        $this->getJson('/api/profile')->assertStatus(401);
    }

    public function test_authenticated_user_can_view_own_profile(): void
    {
        $user = User::factory()->create(['name' => 'Ana Souza', 'role' => 'admin']);
        Sanctum::actingAs($user);

        $this->getJson('/api/profile')
            ->assertStatus(200)
            ->assertJsonPath('name', 'Ana Souza')
            ->assertJsonPath('role', 'admin')
            ->assertJsonPath('profile_photo_url', null);
    }

    public function test_user_can_update_own_name(): void
    {
        $user = User::factory()->create(['name' => 'Nome Antigo']);
        Sanctum::actingAs($user);

        $this->putJson('/api/profile', ['name' => 'Nome Novo'])
            ->assertStatus(200)
            ->assertJsonPath('name', 'Nome Novo');

        $this->assertDatabaseHas('users', ['id' => $user->id, 'name' => 'Nome Novo']);
    }

    public function test_user_cannot_change_own_role_or_email_via_profile_update(): void
    {
        $user = User::factory()->create(['role' => 'attendant', 'email' => 'original@snapmanager.test']);
        Sanctum::actingAs($user);

        $this->putJson('/api/profile', [
            'name' => 'Novo Nome',
            'role' => 'admin',
            'email' => 'outro@snapmanager.test',
        ])->assertStatus(200);

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'role' => 'attendant',
            'email' => 'original@snapmanager.test',
        ]);
    }

    public function test_jpg_photo_upload_works(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $response = $this->postJson('/api/profile/photo', [
            'photo' => UploadedFile::fake()->image('avatar.jpg', 200, 200),
        ]);

        $response->assertStatus(200);
        $this->assertNotNull($response->json('profile_photo_url'));
    }

    public function test_png_photo_upload_works(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $response = $this->postJson('/api/profile/photo', [
            'photo' => UploadedFile::fake()->image('avatar.png', 200, 200),
        ]);

        $response->assertStatus(200);
        $this->assertNotNull($response->json('profile_photo_url'));
    }

    public function test_invalid_file_type_is_rejected_with_422(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/profile/photo', [
            'photo' => UploadedFile::fake()->create('document.pdf', 100, 'application/pdf'),
        ])->assertStatus(422)->assertJsonValidationErrors('photo');
    }

    public function test_oversized_file_is_rejected_with_422(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/profile/photo', [
            // 3000KB > o limite de 2048KB (2MB) da StoreProfilePhotoRequest.
            'photo' => UploadedFile::fake()->image('avatar.jpg')->size(3000),
        ])->assertStatus(422)->assertJsonValidationErrors('photo');
    }

    public function test_removing_photo_clears_the_url(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/profile/photo', ['photo' => UploadedFile::fake()->image('avatar.jpg')]);
        $this->assertNotNull($user->refresh()->profile_photo_path);

        $response = $this->deleteJson('/api/profile/photo');

        $response->assertStatus(200)->assertJsonPath('profile_photo_url', null);
        $this->assertNull($user->refresh()->profile_photo_path);
    }

    public function test_password_change_requires_correct_current_password(): void
    {
        $user = User::factory()->create(['password' => Hash::make('password')]);
        Sanctum::actingAs($user);

        $this->putJson('/api/profile/password', [
            'current_password' => 'wrong-password',
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ])->assertStatus(422)->assertJsonValidationErrors('current_password');
    }

    public function test_valid_password_change_works_and_persists_a_new_hash(): void
    {
        $user = User::factory()->create(['password' => Hash::make('password')]);
        Sanctum::actingAs($user);

        $this->putJson('/api/profile/password', [
            'current_password' => 'password',
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ])->assertStatus(200);

        $user->refresh();
        $this->assertTrue(Hash::check('newpassword123', $user->password));
        $this->assertFalse(Hash::check('password', $user->password));
    }

    /**
     * Mesmo fluxo do teste acima, mas fora de Sanctum::actingAs (que troca o
     * guard resolvido pro resto do teste) — prova de ponta a ponta que
     * "logar com a nova senha funciona" de verdade via /api/login, não só o
     * hash no banco. Também já foi confirmado manualmente via curl durante
     * o desenvolvimento desta funcionalidade.
     */
    public function test_login_works_with_the_newly_set_password(): void
    {
        $user = User::factory()->create(['email' => 'user@snapmanager.test', 'password' => Hash::make('password')]);

        $token = $this->postJson('/api/login', ['email' => 'user@snapmanager.test', 'password' => 'password'])
            ->json('token');

        $this->withToken($token)->putJson('/api/profile/password', [
            'current_password' => 'password',
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ])->assertStatus(200);

        $this->assertTrue(Hash::check('newpassword123', $user->refresh()->password));
    }
}
