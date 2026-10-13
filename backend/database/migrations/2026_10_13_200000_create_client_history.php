<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Histórico do cliente.
 *
 * Só guarda o que NÃO existe em outro lugar:
 *  - clients.preference_notes: as "Notas de Preferência" (como o cliente
 *    gosta de ser atendido e fotografado). Separada de clients.notes, que
 *    continua sendo a observação geral do cadastro.
 *  - client_history_entries: anotações MANUAIS da linha do tempo. Os
 *    eventos automáticos (ensaios, pedidos, contratos, galerias) são
 *    montados na consulta a partir das tabelas de origem — nada duplicado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->text('preference_notes')->nullable()->after('notes');
        });

        Schema::create('client_history_entries', function (Blueprint $table) {
            $table->id();
            // O cliente é excluído só por soft delete; se um dia for apagado
            // de verdade, as anotações dele vão junto.
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            // Autor: se o usuário sair do sistema, a anotação fica (sem autor).
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            // Ensaio relacionado (opcional, sempre do mesmo cliente).
            $table->foreignId('appointment_id')->nullable()->constrained('appointments')->nullOnDelete();
            $table->string('category', 32);
            $table->string('title', 120);
            $table->text('notes')->nullable();
            $table->date('event_date');
            $table->boolean('is_testimonial_candidate')->default(false);
            $table->timestamps();

            $table->index(['client_id', 'event_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_history_entries');

        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn('preference_notes');
        });
    }
};
