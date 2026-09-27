<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Colunas do quadro de produção. São dinâmicas de propósito: cada estúdio
 * organiza a produção do seu jeito ("Agendado → Em edição → Revisão" num,
 * "Backlog → Produção → Entrega" noutro), então a etapa não é um enum fixo
 * como OrderStatus — é dado editável pelo administrador.
 *
 * `is_done` marca a(s) coluna(s) que representam trabalho finalizado: ao
 * entrar numa delas, a tarefa ganha completed_at (ver KanbanTask). Isso é
 * só do Kanban — não mexe no status do pedido.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kanban_columns', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->unsignedInteger('position')->default(0);
            $table->boolean('is_done')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // Toda leitura do quadro ordena por position.
            $table->index('position', 'kanban_columns_position_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kanban_columns');
    }
};
