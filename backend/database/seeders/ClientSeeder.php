<?php

namespace Database\Seeders;

use App\Models\Client;
use Illuminate\Database\Seeder;

class ClientSeeder extends Seeder
{
    public function run(): void
    {
        // Os CPFs abaixo são fictícios, mas matematicamente válidos (passam
        // no algoritmo de dígito verificador) — necessário desde que a
        // validação de CPF passou a rejeitar sequências repetidas
        // (111.111.111-11 etc.), que os dados de exemplo antigos usavam.
        Client::create([
            'name' => 'Maria Fernandes',
            'email' => 'maria.fernandes@example.com',
            'phone' => '(11) 98888-1111',
            'document' => '123.456.789-09',
            'zip_code' => '01305000',
            'street' => 'Rua Augusta',
            'number' => '123',
            'complement' => 'Apto 45',
            'neighborhood' => 'Consolação',
            'city' => 'São Paulo',
            'state' => 'SP',
            'notes' => 'Prefere contato por WhatsApp.',
        ]);

        Client::create([
            'name' => 'João Oliveira',
            'email' => 'joao.oliveira@example.com',
            'phone' => '(11) 97777-2222',
            'document' => '987.654.321-00',
            'zip_code' => '01310100',
            'street' => 'Avenida Paulista',
            'number' => '900',
            'complement' => null,
            'neighborhood' => 'Bela Vista',
            'city' => 'São Paulo',
            'state' => 'SP',
            'notes' => null,
        ]);

        Client::create([
            'name' => 'Empresa XYZ Eventos Ltda',
            'email' => 'contato@xyzeventos.com',
            'phone' => '(11) 3333-4444',
            'document' => '12.345.678/0001-99',
            'zip_code' => '01001000',
            'street' => 'Rua Comercial',
            'number' => '45',
            'complement' => 'Sala 10',
            'neighborhood' => 'Sé',
            'city' => 'São Paulo',
            'state' => 'SP',
            'notes' => 'Cliente corporativo, fecha pacotes de cobertura de eventos.',
        ]);

        Client::create([
            'name' => 'Patrícia Ramos',
            'email' => 'patricia.ramos@example.com',
            'phone' => '(11) 96666-5555',
            'document' => '456.789.123-64',
            'zip_code' => '01305000',
            'street' => 'Rua Augusta',
            'number' => '500',
            'complement' => null,
            'neighborhood' => 'Consolação',
            'city' => 'São Paulo',
            'state' => 'SP',
            'notes' => 'Indicada por cliente antigo.',
        ]);

        Client::create([
            'name' => 'Studio Elegance Casamentos',
            'email' => 'contato@eleganceeventos.com',
            'phone' => '(11) 4444-5555',
            'document' => '98.765.432/0001-11',
            'zip_code' => '01419000',
            'street' => 'Alameda Santos',
            'number' => '1200',
            'complement' => null,
            'neighborhood' => 'Jardim Paulista',
            'city' => 'São Paulo',
            'state' => 'SP',
            'notes' => 'Cerimonialista parceira, indica cobertura de casamentos.',
        ]);
    }
}
