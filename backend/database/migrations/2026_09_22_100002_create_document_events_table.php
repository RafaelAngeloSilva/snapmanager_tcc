<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Histórico do processo de assinatura. Só cresce (sem updated_at).
 *
 * `dedupe_key` torna o webhook idempotente NO BANCO: eventos que descrevem
 * o mesmo fato ("signatário X assinou", "documento finalizado") recebem a
 * mesma chave — venham do webhook, de um reenvio do webhook ou da
 * sincronização manual —, e o índice único impede a segunda linha.
 * Eventos que podem legitimamente se repetir (ex.: "solicitação reenviada")
 * ficam com a chave nula, que não participa da unicidade.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained('documents')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event');
            $table->text('description')->nullable();
            $table->json('metadata')->nullable();
            $table->string('dedupe_key', 191)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['document_id', 'dedupe_key'], 'document_events_dedupe_unique');
            $table->index(['document_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_events');
    }
};
