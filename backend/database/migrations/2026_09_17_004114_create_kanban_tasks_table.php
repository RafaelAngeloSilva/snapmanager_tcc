<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tarefas (cards) do quadro de produção.
 *
 * Decisão de modelagem: NÃO existe par source_type/source_id polimórfico.
 * A origem do card é derivada das FKs explícitas — appointment_id preenchido
 * => veio da agenda; order_id preenchido => veio de um pedido; nenhum dos
 * dois => demanda manual (ver KanbanTask::origin()). FK explícita dá
 * integridade referencial de verdade, que um par polimórfico não daria, e
 * evita guardar a mesma informação duas vezes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kanban_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kanban_column_id')->constrained('kanban_columns')->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();

            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->string('priority')->default('medium');
            $table->date('due_date')->nullable();
            $table->unsignedInteger('position')->default(0);

            // Vínculos com o restante do sistema. Todos opcionais: um card
            // manual não tem nenhum deles.
            $table->foreignId('client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->foreignId('appointment_id')->nullable()->constrained('appointments')->nullOnDelete();
            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->foreignId('gallery_id')->nullable()->constrained('galleries')->nullOnDelete();

            $table->dateTime('completed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            // Consulta principal do quadro: cards de uma coluna, em ordem.
            $table->index(['kanban_column_id', 'position'], 'kanban_tasks_column_position_idx');
            // Filtro "Minhas tarefas".
            $table->index('assigned_to', 'kanban_tasks_assigned_to_idx');
        });

        // Um agendamento/pedido só pode virar card uma vez. Índice único
        // PARCIAL (só linhas não excluídas) para que, se o card for
        // removido do quadro, o mesmo agendamento possa ser adicionado de
        // novo depois — mesmo padrão já usado em clients.document.
        DB::statement('CREATE UNIQUE INDEX kanban_tasks_appointment_unique ON kanban_tasks (appointment_id) WHERE appointment_id IS NOT NULL AND deleted_at IS NULL');
        DB::statement('CREATE UNIQUE INDEX kanban_tasks_order_unique ON kanban_tasks (order_id) WHERE order_id IS NOT NULL AND deleted_at IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('kanban_tasks');
    }
};
