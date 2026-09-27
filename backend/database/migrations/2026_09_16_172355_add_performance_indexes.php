<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Índices adicionados a partir de uma auditoria real das queries existentes
 * (ver relatório de otimização) — não é "índice em toda coluna". Cada um
 * corresponde a uma consulta que já existe no código:
 *
 * - Colunas de foreign key (`foreignId()->constrained()`) NÃO ganham índice
 *   automático no PostgreSQL (diferente do MySQL) — só a constraint em si.
 *   As FKs abaixo são filtradas em WHERE com frequência real, então valem
 *   o índice; as que só existem pra integridade referencial (nunca
 *   filtradas), como orders.appointment_id e status_histories.changed_by,
 *   foram deixadas de fora de propósito.
 * - `galleries.access_token` já tem índice único (criado junto com
 *   `->unique()` na migration original) — nada a fazer ali.
 * - `orders.status`, `appointments.status`, `galleries.status` já tinham
 *   índice simples desde a criação das tabelas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // OrderController::index() filtra por client_id.
            $table->index('client_id', 'orders_client_id_idx');

            // Usado junto em DashboardController (pedidos atrasados/prazo
            // próximo: status NOT IN (...) AND due_date < ?) e no filtro de
            // due_date da listagem — a combinação é mais útil que os dois
            // campos indexados separadamente.
            $table->index(['status', 'due_date'], 'orders_status_due_date_idx');
        });

        Schema::table('galleries', function (Blueprint $table) {
            // GalleryController::index() filtra por client_id.
            $table->index('client_id', 'galleries_client_id_idx');

            // ExpireGalleries (status='active' AND expires_at < now) e
            // Gallery::effectiveStatus()/isAccessible(), chamados toda vez
            // que uma galeria é carregada.
            $table->index(['status', 'expires_at'], 'galleries_status_expires_at_idx');

            // DashboardController::gallerySummary() conta
            // whereNull/whereNotNull nessa coluna a cada carga do Dashboard.
            $table->index('selection_completed_at', 'galleries_selection_completed_at_idx');
        });

        Schema::table('gallery_images', function (Blueprint $table) {
            // Toda tela de galeria (interna e pública) carrega as fotos com
            // WHERE gallery_id = ? ORDER BY sort_order, id.
            $table->index(['gallery_id', 'sort_order'], 'gallery_images_gallery_sort_idx');

            // GalleryController::index() conta fotos selecionadas por
            // galeria via withCount (WHERE gallery_id = ? AND is_selected).
            $table->index(['gallery_id', 'is_selected'], 'gallery_images_gallery_selected_idx');
        });

        Schema::table('status_histories', function (Blueprint $table) {
            // Carregado toda vez que um pedido é aberto/editado/tem status
            // alterado (Order::statusHistories(), WHERE order_id = ?).
            $table->index('order_id', 'status_histories_order_id_idx');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex('orders_client_id_idx');
            $table->dropIndex('orders_status_due_date_idx');
        });

        Schema::table('galleries', function (Blueprint $table) {
            $table->dropIndex('galleries_client_id_idx');
            $table->dropIndex('galleries_status_expires_at_idx');
            $table->dropIndex('galleries_selection_completed_at_idx');
        });

        Schema::table('gallery_images', function (Blueprint $table) {
            $table->dropIndex('gallery_images_gallery_sort_idx');
            $table->dropIndex('gallery_images_gallery_selected_idx');
        });

        Schema::table('status_histories', function (Blueprint $table) {
            $table->dropIndex('status_histories_order_id_idx');
        });
    }
};
