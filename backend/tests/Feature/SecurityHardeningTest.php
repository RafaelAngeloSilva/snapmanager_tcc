<?php

namespace Tests\Feature;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Client;
use App\Models\Gallery;
use App\Models\Order;
use App\Models\User;
use App\Support\FieldLimits;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Revisão de segurança, validação e integridade (12/10/2026):
 * troca de senha, cadastro público, rate limit, limites de texto, valores
 * numéricos (incl. "-0"), vínculos entre entidades, mass assignment,
 * controle de acesso e exclusão de cliente.
 */
class SecurityHardeningTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsRole(string $role): User
    {
        $user = User::factory()->create(['role' => $role]);
        Sanctum::actingAs($user);

        return $user;
    }

    // =============================================================== senha

    public function test_new_password_equal_to_current_is_rejected_by_the_backend(): void
    {
        $user = User::factory()->create(['password' => 'SenhaAtual123']);
        Sanctum::actingAs($user);

        $this->putJson('/api/profile/password', [
            'current_password' => 'SenhaAtual123',
            'password' => 'SenhaAtual123',
            'password_confirmation' => 'SenhaAtual123',
        ])->assertStatus(422)
            ->assertJsonPath('errors.password.0', 'A nova senha deve ser diferente da senha atual.');

        $this->assertTrue(Hash::check('SenhaAtual123', $user->fresh()->password));
    }

    public function test_valid_new_password_is_hashed_and_other_sessions_are_revoked(): void
    {
        $user = User::factory()->create(['password' => 'SenhaAtual123']);
        $current = $user->createToken('este-aparelho')->plainTextToken;
        $user->createToken('outro-aparelho');
        $user->createToken('mais-um');

        $this->withToken($current)->putJson('/api/profile/password', [
            'current_password' => 'SenhaAtual123',
            'password' => 'NovaSenha456',
            'password_confirmation' => 'NovaSenha456',
        ])->assertOk();

        $user->refresh();
        $this->assertTrue(Hash::check('NovaSenha456', $user->password));
        $this->assertNotSame('NovaSenha456', $user->password, 'A senha é guardada como hash.');
        // Só o token de quem trocou a senha sobrevive.
        $this->assertSame(['este-aparelho'], $user->tokens()->pluck('name')->all());
    }

    public function test_wrong_current_password_and_weak_or_oversized_new_password_are_rejected(): void
    {
        $user = User::factory()->create(['password' => 'SenhaAtual123']);
        Sanctum::actingAs($user);

        $this->putJson('/api/profile/password', [
            'current_password' => 'errada',
            'password' => 'NovaSenha456',
            'password_confirmation' => 'NovaSenha456',
        ])->assertStatus(422)->assertJsonValidationErrors('current_password');

        $this->putJson('/api/profile/password', [
            'current_password' => 'SenhaAtual123',
            'password' => 'curta',
            'password_confirmation' => 'curta',
        ])->assertStatus(422)->assertJsonValidationErrors('password');

        $tooLong = str_repeat('a', FieldLimits::PASSWORD_MAX + 1);
        $this->putJson('/api/profile/password', [
            'current_password' => 'SenhaAtual123',
            'password' => $tooLong,
            'password_confirmation' => $tooLong,
        ])->assertStatus(422)->assertJsonValidationErrors('password');

        $this->assertTrue(Hash::check('SenhaAtual123', $user->fresh()->password));
    }

    public function test_password_reset_revokes_every_existing_token(): void
    {
        $user = User::factory()->create(['email' => 'ana@estudio.test']);
        $user->createToken('celular');
        $user->createToken('notebook');
        $token = Password::createToken($user);

        $this->postJson('/api/reset-password', [
            'token' => $token,
            'email' => 'ana@estudio.test',
            'password' => 'Redefinida789',
            'password_confirmation' => 'Redefinida789',
        ])->assertOk();

        $this->assertSame(0, $user->tokens()->count());
    }

    // ===================================================== cadastro público

    public function test_public_registration_is_open_by_default(): void
    {
        $this->getJson('/api/auth/registration/status')->assertOk()->assertExactJson(['available' => true]);

        $this->postJson('/api/register', [
            'name' => 'Nova Pessoa',
            'email' => 'nova@example.com',
            'password' => 'SenhaForte123',
            'password_confirmation' => 'SenhaForte123',
        ])->assertCreated();

        $this->assertDatabaseHas('users', ['email' => 'nova@example.com', 'role' => 'attendant']);
    }

    public function test_public_registration_can_be_closed(): void
    {
        config(['auth.registration_enabled' => false]);

        $this->getJson('/api/auth/registration/status')->assertOk()->assertExactJson(['available' => false]);

        $this->postJson('/api/register', [
            'name' => 'Invasor',
            'email' => 'invasor@example.com',
            'password' => 'SenhaForte123',
            'password_confirmation' => 'SenhaForte123',
        ])->assertForbidden();

        $this->assertDatabaseMissing('users', ['email' => 'invasor@example.com']);
    }

    public function test_when_enabled_registration_always_creates_an_attendant_even_if_role_is_sent(): void
    {
        config(['auth.registration_enabled' => true]);

        $this->postJson('/api/register', [
            'name' => 'Nova Atendente',
            'email' => 'nova@estudio.test',
            'password' => 'SenhaForte123',
            'password_confirmation' => 'SenhaForte123',
            'role' => 'admin',
        ])->assertCreated()->assertJsonPath('user.role', 'attendant');

        $this->assertSame('attendant', User::where('email', 'nova@estudio.test')->first()->role->value);
    }

    // ========================================================== rate limit

    public function test_login_is_rate_limited(): void
    {
        User::factory()->create(['email' => 'alvo@estudio.test', 'password' => 'SenhaCerta123']);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/login', ['email' => 'alvo@estudio.test', 'password' => "chute-{$i}"])->assertStatus(422);
        }

        $this->postJson('/api/login', ['email' => 'alvo@estudio.test', 'password' => 'SenhaCerta123'])
            ->assertStatus(429)
            ->assertJsonPath('message', 'Muitas tentativas. Aguarde um minuto e tente novamente.');
    }

    // ===================================================== limites de texto

    public function test_text_fields_accept_the_limit_and_reject_one_character_more(): void
    {
        $this->actingAsRole('attendant');

        $this->postJson('/api/clients', [
            'name' => str_repeat('a', FieldLimits::PERSON_NAME),
            'notes' => str_repeat('n', FieldLimits::NOTES),
        ])->assertCreated();

        $this->postJson('/api/clients', [
            'name' => str_repeat('a', FieldLimits::PERSON_NAME + 1),
            'notes' => str_repeat('n', FieldLimits::NOTES + 1),
        ])->assertStatus(422)->assertJsonValidationErrors(['name', 'notes']);

        $client = Client::factory()->create();

        $this->postJson('/api/orders', [
            'client_id' => $client->id,
            'title' => str_repeat('t', FieldLimits::TITLE + 1),
            'description' => str_repeat('d', FieldLimits::DESCRIPTION + 1),
        ])->assertStatus(422)->assertJsonValidationErrors(['title', 'description']);
    }

    public function test_phone_rejects_letters_and_arrays_do_not_cause_server_errors(): void
    {
        $this->actingAsRole('attendant');

        $this->postJson('/api/clients', ['name' => 'Maria', 'phone' => 'ligue-me'])
            ->assertStatus(422)->assertJsonValidationErrors('phone');

        $this->postJson('/api/clients', ['name' => 'Maria', 'phone' => '(11) 98765-4321'])->assertCreated();

        // Antes: TypeError em strtoupper/preg_replace → 500.
        $this->postJson('/api/clients', ['name' => 'Maria', 'state' => ['SP'], 'zip_code' => ['01001000']])
            ->assertStatus(422)->assertJsonValidationErrors(['state', 'zip_code']);

        // Filtros de listagem: texto/array em coluna inteira não vira 500.
        $this->getJson('/api/clients?search[]=x')->assertStatus(422);
        $this->getJson('/api/orders?client_id=abc')->assertStatus(422);
        $this->getJson('/api/galleries?client_id=1;drop')->assertStatus(422);
    }

    // ============================================================== números

    /**
     * @return array<string, array{0: mixed, 1: int, 2: ?string}>
     */
    public static function totals(): array
    {
        return [
            'zero' => [0, 201, '0.00'],
            'menos zero (string)' => ['-0', 201, '0.00'],
            'menos zero com casas' => ['-0.00', 201, '0.00'],
            'menos zero (float JSON)' => [-0.0, 201, '0.00'],
            'positivo' => ['1500.50', 201, '1500.50'],
            'float JSON' => [10.5, 201, '10.50'],
            'máximo da coluna' => ['99999999.99', 201, '99999999.99'],
            'negativo' => ['-1', 422, null],
            'negativo pequeno' => ['-0.01', 422, null],
            'notação científica' => ['1e5', 422, null],
            'três casas' => ['10.555', 422, null],
            'texto' => ['abc', 422, null],
            'NaN' => ['NaN', 422, null],
            'Infinity' => ['Infinity', 422, null],
            'estoura decimal(10,2)' => ['100000000', 422, null],
            'vírgula' => ['10,50', 422, null],
            'array' => [['10'], 422, null],
            'booleano' => [true, 422, null],
        ];
    }

    /**
     * @dataProvider totals
     */
    public function test_order_total_is_normalized_and_validated(mixed $total, int $status, ?string $stored): void
    {
        $this->actingAsRole('attendant');
        $client = Client::factory()->create();

        $response = $this->postJson('/api/orders', ['client_id' => $client->id, 'title' => 'Álbum', 'total' => $total])
            ->assertStatus($status);

        if ($stored !== null) {
            $saved = (string) Order::findOrFail($response->json('data.id'))->total;
            $this->assertSame($stored, number_format((float) $saved, 2, '.', ''));
            $this->assertFalse(str_starts_with($saved, '-'), 'Nunca grava "-0" nem negativo.');
        } else {
            $response->assertJsonValidationErrors('total');
        }
    }

    public function test_kanban_positions_must_be_non_negative_integers(): void
    {
        $this->actingAsRole('admin');

        $column = $this->postJson('/api/kanban/columns', ['name' => 'A fazer'])->assertCreated()->json('data.id');
        $task = $this->postJson('/api/kanban/tasks', ['kanban_column_id' => $column, 'title' => 'Editar fotos'])->assertCreated()->json('data.id');

        foreach (['-1', '1.5', 'abc', FieldLimits::POSITION_MAX + 1] as $position) {
            $this->patchJson("/api/kanban/tasks/{$task}/move", ['kanban_column_id' => $column, 'position' => $position])
                ->assertStatus(422)->assertJsonValidationErrors('position');
        }

        $this->patchJson("/api/kanban/tasks/{$task}/move", ['kanban_column_id' => $column, 'position' => 0])->assertOk();
    }

    // ================================================ vínculos entre entidades

    public function test_order_cannot_link_an_appointment_from_another_client(): void
    {
        $this->actingAsRole('attendant');
        [$mine, $other] = Client::factory()->count(2)->create();
        $foreign = Appointment::factory()->for($other)->create();
        $own = Appointment::factory()->for($mine)->create();

        $this->postJson('/api/orders', ['client_id' => $mine->id, 'title' => 'Álbum', 'appointment_id' => $foreign->id])
            ->assertStatus(422)->assertJsonValidationErrors('appointment_id');

        $this->postJson('/api/orders', ['client_id' => $mine->id, 'title' => 'Álbum', 'appointment_id' => $own->id])
            ->assertCreated();
    }

    public function test_gallery_cannot_link_an_order_from_another_client(): void
    {
        $this->actingAsRole('admin');
        [$mine, $other] = Client::factory()->count(2)->create();
        $foreign = Order::factory()->for($other)->create();

        $this->postJson('/api/galleries', ['client_id' => $mine->id, 'title' => 'Ensaio', 'order_id' => $foreign->id])
            ->assertStatus(422)->assertJsonValidationErrors('order_id');
    }

    public function test_deleted_client_cannot_receive_new_records_but_old_ones_stay_editable(): void
    {
        $this->actingAsRole('attendant');
        $client = Client::factory()->create();
        $old = Appointment::factory()->for($client)->create(['status' => AppointmentStatus::Completed->value, 'starts_at' => now()->subDays(10), 'ends_at' => now()->subDays(10)->addHour()]);
        $client->delete();

        $this->postJson('/api/appointments', [
            'client_id' => $client->id,
            'title' => 'Ensaio',
            'starts_at' => now()->addDay()->toDateTimeString(),
            'ends_at' => now()->addDay()->addHour()->toDateTimeString(),
        ])->assertStatus(422)->assertJsonValidationErrors('client_id');

        $this->postJson('/api/orders', ['client_id' => $client->id, 'title' => 'Álbum'])
            ->assertStatus(422)->assertJsonValidationErrors('client_id');

        // Editar o histórico mantendo o cliente (já excluído) continua possível.
        $this->putJson("/api/appointments/{$old->id}", ['client_id' => $client->id, 'notes' => 'Entregue.'])->assertOk();
    }

    // ===================================================== mass assignment

    public function test_extra_attributes_cannot_change_protected_fields(): void
    {
        $user = $this->actingAsRole('attendant');

        $this->putJson('/api/profile', ['name' => 'Novo Nome', 'role' => 'admin', 'email' => 'outro@x.test'])->assertOk();
        $user->refresh();
        $this->assertSame('attendant', $user->role->value);
        $this->assertNotSame('outro@x.test', $user->email);

        $order = Order::factory()->create();
        $this->putJson("/api/orders/{$order->id}", [
            'title' => 'Atualizado',
            'tracking_token' => str_repeat('a', 40),
            'tracking_enabled_at' => now()->toDateTimeString(),
            'id' => 999,
        ])->assertOk();

        $order->refresh();
        $this->assertNull($order->tracking_token);
        $this->assertNull($order->tracking_enabled_at);
        $this->assertNotSame(999, $order->id);
    }

    // ===================================================== controle de acesso

    public function test_roles_cannot_reach_resources_outside_their_profile_by_changing_ids(): void
    {
        $client = Client::factory()->create();
        $gallery = Gallery::factory()->create();

        $this->actingAsRole('editor');
        $this->getJson("/api/clients/{$client->id}")->assertForbidden();
        $this->getJson('/api/clients')->assertForbidden();

        $this->actingAsRole('photographer');
        $this->deleteJson("/api/clients/{$client->id}")->assertForbidden();
        $this->deleteJson("/api/galleries/{$gallery->id}")->assertForbidden();
        $this->putJson("/api/galleries/{$gallery->id}", ['title' => 'x'])->assertForbidden();

        $this->actingAsRole('attendant');
        $this->deleteJson("/api/galleries/{$gallery->id}")->assertForbidden();

        $this->assertNotSoftDeleted($client);
        $this->assertNotSoftDeleted($gallery);
    }

    public function test_photographer_cannot_edit_a_kanban_task_assigned_to_someone_else(): void
    {
        $admin = $this->actingAsRole('admin');
        $other = User::factory()->create(['role' => 'photographer']);
        $column = $this->postJson('/api/kanban/columns', ['name' => 'A fazer'])->json('data.id');
        $task = $this->postJson('/api/kanban/tasks', ['kanban_column_id' => $column, 'title' => 'Do outro', 'assigned_to' => $other->id])->json('data.id');

        $this->actingAsRole('photographer');
        $this->putJson("/api/kanban/tasks/{$task}", ['title' => 'Invadido'])->assertForbidden();
        $this->deleteJson("/api/kanban/tasks/{$task}")->assertForbidden();
    }

    public function test_api_never_returns_password_hashes_or_tokens(): void
    {
        $user = $this->actingAsRole('admin');
        $user->createToken('x');

        foreach (['/api/me', '/api/profile', '/api/users'] as $url) {
            $body = $this->getJson($url)->assertOk()->getContent();
            $this->assertStringNotContainsString($user->password, $body);
            $this->assertStringNotContainsString('"password"', $body);
            $this->assertStringNotContainsString('remember_token', $body);
        }
    }

    // ================================================== exclusão de cliente

    public function test_deleting_a_client_cancels_only_its_pending_appointments_in_one_go(): void
    {
        $this->actingAsRole('attendant');
        $client = Client::factory()->create();
        $other = Client::factory()->create();

        $future = Appointment::factory()->for($client)->create(['status' => AppointmentStatus::Scheduled->value]);
        $confirmed = Appointment::factory()->for($client)->create(['status' => AppointmentStatus::Confirmed->value]);
        $stalePast = Appointment::factory()->for($client)->create([
            'status' => AppointmentStatus::Scheduled->value,
            'starts_at' => now()->subDays(3),
            'ends_at' => now()->subDays(3)->addHour(),
        ]);
        $completed = Appointment::factory()->for($client)->create([
            'status' => AppointmentStatus::Completed->value,
            'starts_at' => now()->subMonth(),
            'ends_at' => now()->subMonth()->addHour(),
        ]);
        $alreadyCancelled = Appointment::factory()->for($client)->create(['status' => AppointmentStatus::Cancelled->value]);
        $otherClients = Appointment::factory()->for($other)->create(['status' => AppointmentStatus::Scheduled->value]);
        $order = Order::factory()->for($client)->create();

        $this->deleteJson("/api/clients/{$client->id}")->assertNoContent();

        $this->assertSoftDeleted($client);
        foreach ([$future, $confirmed, $stalePast] as $appointment) {
            $this->assertSame(AppointmentStatus::Cancelled, $appointment->fresh()->status, "Agendamento {$appointment->id} deveria estar cancelado.");
        }
        $this->assertSame(AppointmentStatus::Completed, $completed->fresh()->status, 'Histórico concluído não muda.');
        $this->assertSame(AppointmentStatus::Cancelled, $alreadyCancelled->fresh()->status);
        $this->assertSame(AppointmentStatus::Scheduled, $otherClients->fresh()->status, 'Agendamentos de outro cliente não são tocados.');
        // Nada é apagado fisicamente: agendamentos e pedidos continuam no banco.
        $this->assertNotSoftDeleted($future);
        $this->assertNotSoftDeleted($order);

        // Na agenda, o ensaio aparece como cancelado (e o cliente como removido).
        $this->getJson("/api/appointments/{$future->id}")
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.client', null);
    }

    public function test_client_deletion_is_atomic(): void
    {
        $this->actingAsRole('attendant');
        $client = Client::factory()->create();
        $appointment = Appointment::factory()->for($client)->create(['status' => AppointmentStatus::Scheduled->value]);

        // Falha no meio (ex.: banco recusa o soft delete): o cancelamento
        // dos agendamentos também é desfeito.
        Client::deleting(function () {
            throw new \RuntimeException('falha simulada');
        });

        try {
            $client->deleteAndCancelPendingAppointments();
            $this->fail('A exclusão deveria ter falhado.');
        } catch (\RuntimeException) {
            // esperado
        } finally {
            Client::flushEventListeners();
        }

        $this->assertNotSoftDeleted($client);
        $this->assertSame(AppointmentStatus::Scheduled, $appointment->fresh()->status);
    }

    // ======================================= achados da revisão independente

    public function test_login_and_rate_limiter_handle_array_email_without_server_error(): void
    {
        $this->postJson('/api/login', ['email' => ['a@b.c'], 'password' => 'x'])->assertStatus(422);
    }

    public function test_closed_registration_does_not_reveal_which_emails_exist(): void
    {
        config(['auth.registration_enabled' => false]);
        User::factory()->create(['email' => 'existe@estudio.test']);

        foreach (['existe@estudio.test', 'nao-existe@estudio.test'] as $email) {
            $this->postJson('/api/register', [
                'name' => 'X Y',
                'email' => $email,
                'password' => 'SenhaForte123',
                'password_confirmation' => 'SenhaForte123',
            ])->assertForbidden()->assertJsonMissingPath('errors');
        }
    }

    public function test_legacy_password_longer_than_72_characters_still_logs_in(): void
    {
        $legacy = str_repeat('s', 80);
        User::factory()->create(['email' => 'antigo@estudio.test', 'password' => $legacy]);

        $this->postJson('/api/login', ['email' => 'antigo@estudio.test', 'password' => $legacy])->assertOk();
    }

    public function test_changing_only_the_client_cannot_keep_a_link_from_the_previous_client(): void
    {
        $this->actingAsRole('admin');
        [$a, $b] = Client::factory()->count(2)->create();
        $appointmentOfA = Appointment::factory()->for($a)->create();
        $order = Order::factory()->for($a)->create(['appointment_id' => $appointmentOfA->id]);
        $gallery = Gallery::factory()->for($a)->create(['order_id' => $order->id]);

        $this->putJson("/api/orders/{$order->id}", ['client_id' => $b->id])
            ->assertStatus(422)->assertJsonValidationErrors('appointment_id');
        $this->putJson("/api/galleries/{$gallery->id}", ['client_id' => $b->id])
            ->assertStatus(422)->assertJsonValidationErrors('order_id');

        // Trocando o cliente E removendo o vínculo, passa.
        $this->putJson("/api/orders/{$order->id}", ['client_id' => $b->id, 'appointment_id' => null])->assertOk();
        $this->assertSame($b->id, $order->fresh()->client_id);
        $this->assertNull($order->fresh()->appointment_id);
    }

    public function test_phone_needs_digits_and_state_must_be_a_real_uf(): void
    {
        $this->actingAsRole('attendant');

        foreach (['--------', '........', '1234567'] as $phone) {
            $this->postJson('/api/clients', ['name' => 'Maria', 'phone' => $phone])->assertStatus(422)->assertJsonValidationErrors('phone');
        }

        $this->postJson('/api/clients', ['name' => 'Maria', 'state' => 'XX'])->assertStatus(422)->assertJsonValidationErrors('state');
        $this->postJson('/api/clients', ['name' => 'Maria', 'state' => 'sp'])->assertCreated()->assertJsonPath('data.state', 'SP');
    }

    public function test_appointments_can_be_filtered_by_client_for_the_order_form(): void
    {
        $this->actingAsRole('attendant');
        [$a, $b] = Client::factory()->count(2)->create();
        $mine = Appointment::factory()->for($a)->create();
        Appointment::factory()->for($b)->create();

        $this->getJson("/api/appointments?client_id={$a->id}")
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $mine->id);
        $this->getJson('/api/appointments?client_id=abc')->assertStatus(422);
    }

    public function test_check_limits_command_reports_existing_records_out_of_bounds(): void
    {
        $this->artisan('data:check-limits')->expectsOutputToContain('Nenhum registro')->assertSuccessful();

        Client::factory()->create(['name' => str_repeat('n', FieldLimits::PERSON_NAME + 5), 'phone' => 'sem telefone']);

        $this->artisan('data:check-limits', ['--ids' => true])
            ->expectsOutputToContain('clients')
            ->assertSuccessful();
    }
}
