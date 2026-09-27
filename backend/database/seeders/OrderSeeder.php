<?php

namespace Database\Seeders;

use App\Models\Appointment;
use App\Models\Client;
use App\Models\Order;
use Illuminate\Database\Seeder;

class OrderSeeder extends Seeder
{
    public function run(): void
    {
        $maria = Client::where('name', 'Maria Fernandes')->first();
        $joao = Client::where('name', 'João Oliveira')->first();
        $empresa = Client::where('name', 'Empresa XYZ Eventos Ltda')->first();

        $eventAppointment = Appointment::where('title', 'Cobertura evento corporativo')->first();

        Order::create([
            'client_id' => $maria->id,
            'appointment_id' => null,
            'title' => 'Álbum 30x30 - Ensaio gestante',
            'service_type' => 'album',
            'description' => 'Álbum impresso com 30 fotos selecionadas.',
            'due_date' => now()->addDays(10),
            'total' => 890.00,
            'status' => 'pending',
            'notes' => null,
        ]);

        Order::create([
            'client_id' => $joao->id,
            'appointment_id' => null,
            'title' => 'Edição e entrega digital - Book',
            'service_type' => 'digital',
            'description' => '40 fotos editadas em alta resolução.',
            'due_date' => now()->addDays(5),
            'total' => 450.00,
            'status' => 'in_editing',
            'notes' => 'Cliente pediu tratamento em preto e branco em parte das fotos.',
        ]);

        Order::create([
            'client_id' => $empresa->id,
            'appointment_id' => $eventAppointment?->id,
            'title' => 'Cobertura fotográfica evento corporativo',
            'service_type' => 'evento',
            'description' => 'Pacote completo de cobertura + 100 fotos editadas.',
            'due_date' => now()->subDays(1),
            'total' => 3200.00,
            'status' => 'delivered',
            'notes' => 'Entregue via link de download.',
        ]);

        Order::create([
            'client_id' => $joao->id,
            'appointment_id' => null,
            'title' => 'Impressão avulsa 20x30',
            'service_type' => 'impressao',
            'description' => 'Impressão de 5 fotos em papel fotográfico.',
            'due_date' => now()->addDays(2),
            'total' => 120.00,
            'status' => 'cancelled',
            'notes' => 'Cliente desistiu do pedido.',
        ]);

        // Os dois pedidos abaixo cobrem os alertas "overdue_order" e
        // "ready_for_delivery" do dashboard (nenhum dos pedidos acima cai
        // nesses casos: o único atrasado está "delivered", que é excluído
        // da regra de atraso por definição) e servem os passos 9-11 do
        // roteiro de demonstração (ver DEMO.md).
        Order::create([
            'client_id' => $joao->id,
            'appointment_id' => null,
            'title' => 'Retratos corporativos - equipe',
            'service_type' => 'book',
            'description' => '15 retratos individuais para uso no site da empresa.',
            'due_date' => now()->subDays(3),
            'total' => 680.00,
            'status' => 'in_review',
            'notes' => 'Pedido de teste para o alerta de pedido atrasado (overdue) do dashboard.',
        ]);

        Order::create([
            'client_id' => $maria->id,
            'appointment_id' => null,
            'title' => 'Ensaio newborn - pronto',
            'service_type' => 'album',
            'description' => 'Álbum físico finalizado, aguardando retirada pelo cliente.',
            'due_date' => now()->addDays(1),
            'total' => 750.00,
            'status' => 'ready_for_delivery',
            'notes' => 'Pedido de teste para o alerta de pedido pronto para entrega do dashboard.',
        ]);
    }
}
