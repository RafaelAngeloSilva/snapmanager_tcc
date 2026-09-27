<?php

namespace Database\Seeders;

use App\Models\Appointment;
use App\Models\Client;
use App\Models\User;
use Illuminate\Database\Seeder;

class AppointmentSeeder extends Seeder
{
    public function run(): void
    {
        $photographer = User::where('role', 'photographer')->first();

        $maria = Client::where('name', 'Maria Fernandes')->first();
        $joao = Client::where('name', 'João Oliveira')->first();
        $empresa = Client::where('name', 'Empresa XYZ Eventos Ltda')->first();

        Appointment::create([
            'client_id' => $maria->id,
            'user_id' => $photographer->id,
            'title' => 'Ensaio gestante - Maria',
            'session_type' => 'ensaio',
            'starts_at' => now()->addDays(3)->setTime(14, 0),
            'ends_at' => now()->addDays(3)->setTime(16, 0),
            'location' => 'Estúdio SnapManager',
            'status' => 'scheduled',
            'notes' => null,
        ]);

        Appointment::create([
            'client_id' => $joao->id,
            'user_id' => $photographer->id,
            'title' => 'Book individual - João',
            'session_type' => 'book',
            'starts_at' => now()->addDays(5)->setTime(10, 0),
            'ends_at' => now()->addDays(5)->setTime(11, 30),
            'location' => 'Parque Ibirapuera',
            'status' => 'confirmed',
            'notes' => 'Cliente pediu horário pela manhã.',
        ]);

        Appointment::create([
            'client_id' => $empresa->id,
            'user_id' => $photographer->id,
            'title' => 'Cobertura evento corporativo',
            'session_type' => 'evento',
            'starts_at' => now()->subDays(2)->setTime(19, 0),
            'ends_at' => now()->subDays(2)->setTime(23, 0),
            'location' => 'Salão de festas XYZ',
            'status' => 'completed',
            'notes' => 'Evento de fim de ano da empresa.',
        ]);

        // Agendamento com starts_at = hoje, para o alerta "appointment_today"
        // do dashboard e o passo de demonstração terem dado sempre. Ancorado
        // num horário fixo do dia (e não "now()->addHours(3)"), pra não cair
        // em amanhã se o seed for rodado à noite.
        Appointment::create([
            'client_id' => $joao->id,
            'user_id' => $photographer->id,
            'title' => 'Retrato corporativo - sessão de hoje',
            'session_type' => 'book',
            'starts_at' => now()->startOfDay()->addHours(15),
            'ends_at' => now()->startOfDay()->addHours(16),
            'location' => 'Estúdio SnapManager',
            'status' => 'confirmed',
            'notes' => null,
        ]);
    }
}
