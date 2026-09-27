<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        User::factory()->create([
            'name' => 'Ana Souza',
            'email' => 'admin@snapmanager.test',
            'role' => 'admin',
        ]);

        User::factory()->create([
            'name' => 'Bruna Lima',
            'email' => 'atendimento@snapmanager.test',
            'role' => 'attendant',
        ]);

        User::factory()->create([
            'name' => 'Carlos Pereira',
            'email' => 'fotografo@snapmanager.test',
            'role' => 'photographer',
        ]);

        User::factory()->create([
            'name' => 'Diego Santos',
            'email' => 'editor@snapmanager.test',
            'role' => 'editor',
        ]);
    }
}
