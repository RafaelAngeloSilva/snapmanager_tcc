<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Segunda rodada de índices, cobrindo o que a primeira
 * (2026_09_16_172355_add_performance_indexes) não pegou:
 *
 * 1. Chaves estrangeiras SEM índice. No PostgreSQL `foreignId()->constrained()`
 *    cria só a constraint, não o índice. Sem ele, todo eager load
 *    (`with('signers')` → WHERE document_id IN (...)) e todo filtro por FK vira
 *    varredura sequencial da tabela inteira.
 * 2. Colunas usadas em ORDER BY das listagens paginadas (created_at, starts_at,
 *    name) — sem índice o banco precisa ler e ordenar a tabela toda para
 *    devolver só 15 linhas.
 * 3. Buscas com `ILIKE '%termo%'` / `lower(x) LIKE '%termo%'` (clientes e
 *    documentos). Índice B-tree comum não serve para curinga no início; o
 *    índice GIN com pg_trgm serve.
 *
 * Só roda no PostgreSQL (a suíte de testes usa SQLite). Usa IF NOT EXISTS
 * para poder rodar com segurança em bancos que já tenham algum desses índices.
 */
return new class extends Migration
{
    /** @var array<string, string> nome do índice => definição */
    private const BTREE_INDEXES = [
        // Documentos (módulo novo, FKs sem índice)
        'documents_client_id_idx' => 'documents (client_id)',
        'documents_order_id_idx' => 'documents (order_id)',
        'documents_appointment_id_idx' => 'documents (appointment_id)',
        'documents_created_at_id_idx' => 'documents (created_at DESC, id DESC)',
        'document_signers_document_id_idx' => 'document_signers (document_id)',

        // Agenda: listagem ORDER BY starts_at, alertas/dashboard por data, FK do cliente
        'appointments_starts_at_idx' => 'appointments (starts_at)',
        'appointments_client_id_idx' => 'appointments (client_id)',

        // Pedidos: listagem ORDER BY created_at DESC; FK usada por linkOptions/kanban
        'orders_created_at_idx' => 'orders (created_at DESC)',
        'orders_appointment_id_idx' => 'orders (appointment_id)',

        // Galerias: listagem ORDER BY created_at DESC; FK de pedido
        'galleries_created_at_idx' => 'galleries (created_at DESC)',
        'galleries_order_id_idx' => 'galleries (order_id)',

        // Kanban: filtros por cliente e FK de galeria
        'kanban_tasks_client_id_idx' => 'kanban_tasks (client_id)',
        'kanban_tasks_gallery_id_idx' => 'kanban_tasks (gallery_id)',

        // Clientes: listagem ORDER BY name
        'clients_name_idx' => 'clients (name)',

        // Histórico de status carrega o usuário que alterou
        'status_histories_changed_by_idx' => 'status_histories (changed_by)',
    ];

    /** @var array<string, string> índices GIN (pg_trgm) para buscas com curinga */
    private const TRIGRAM_INDEXES = [
        'clients_name_trgm_idx' => 'clients USING gin (name gin_trgm_ops)',
        'clients_email_trgm_idx' => 'clients USING gin (email gin_trgm_ops)',
        'clients_phone_trgm_idx' => 'clients USING gin (phone gin_trgm_ops)',
        'clients_document_trgm_idx' => 'clients USING gin (document gin_trgm_ops)',
        'documents_title_lower_trgm_idx' => 'documents USING gin (lower(title) gin_trgm_ops)',
        'clients_name_lower_trgm_idx' => 'clients USING gin (lower(name) gin_trgm_ops)',
    ];

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach (self::BTREE_INDEXES as $name => $definition) {
            if ($this->tableExists($definition)) {
                DB::statement("CREATE INDEX IF NOT EXISTS {$name} ON {$definition}");
            }
        }

        // pg_trgm vem junto com o PostgreSQL, mas criar a extensão exige
        // permissão de superusuário/owner. Se não der, a busca continua
        // funcionando (só sem esse índice) — não derruba a migration.
        try {
            DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
        } catch (\Throwable $e) {
            Log::warning('pg_trgm indisponível; índices de busca textual não foram criados.', ['message' => $e->getMessage()]);

            return;
        }

        foreach (self::TRIGRAM_INDEXES as $name => $definition) {
            if ($this->tableExists($definition)) {
                DB::statement("CREATE INDEX IF NOT EXISTS {$name} ON {$definition}");
            }
        }

        // Atualiza as estatísticas para o planejador já usar os índices novos.
        DB::statement('ANALYZE');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach (array_keys(self::TRIGRAM_INDEXES + self::BTREE_INDEXES) as $name) {
            DB::statement("DROP INDEX IF EXISTS {$name}");
        }
    }

    private function tableExists(string $definition): bool
    {
        return Schema::hasTable(strtok($definition, ' '));
    }
};
