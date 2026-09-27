<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Acompanhe seu Pedido": link público (sem login) para o cliente ver o
 * andamento do próprio pedido.
 *
 * - tracking_token: token exclusivo do pedido; nulo = acompanhamento
 *   desativado/revogado. Revogar apaga o token, então um link antigo nunca
 *   volta a funcionar — reativar gera um token novo.
 * - tracking_enabled_at: quando o link atual foi ativado.
 * - tracking_last_viewed_at / tracking_views: quando e quantas vezes o
 *   cliente abriu o link (alimenta o alerta do Dashboard).
 *
 * Só ADICIONA colunas nulas/com default: não altera dados existentes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('tracking_token', 64)->nullable()->unique();
            $table->timestamp('tracking_enabled_at')->nullable();
            $table->timestamp('tracking_last_viewed_at')->nullable()->index();
            $table->unsignedInteger('tracking_views')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropUnique(['tracking_token']);
            $table->dropIndex(['tracking_last_viewed_at']);
            $table->dropColumn(['tracking_token', 'tracking_enabled_at', 'tracking_last_viewed_at', 'tracking_views']);
        });
    }
};
