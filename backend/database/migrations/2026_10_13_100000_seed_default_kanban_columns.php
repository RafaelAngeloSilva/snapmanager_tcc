<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Colunas padrão do quadro de Produção.
 *
 * Em uma instalação nova (produção) o quadro nascia SEM colunas, e só o
 * admin pode criá-las. Enquanto isso, "Levar para o quadro" respondia 409
 * e ninguém conseguia usar a Produção. Esta migration cria as colunas
 * padrão SÓ se o quadro estiver vazio: não mexe em quadros já montados,
 * e o admin continua podendo renomear, reordenar ou excluir.
 */
return new class extends Migration
{
    public const COLUMNS = ['A fazer', 'Em andamento', 'Em revisão', 'Concluído'];

    public function up(): void
    {
        if (DB::table('kanban_columns')->exists()) {
            return;
        }

        $now = now();

        DB::table('kanban_columns')->insert(array_map(fn (string $name, int $position) => [
            'name' => $name,
            'position' => $position,
            // Só a última coluna marca a tarefa como concluída.
            'is_done' => $name === 'Concluído',
            'created_by' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ], self::COLUMNS, array_keys(self::COLUMNS)));
    }

    /**
     * Não remove nada: as colunas podem já ter cards.
     */
    public function down(): void {}
};
