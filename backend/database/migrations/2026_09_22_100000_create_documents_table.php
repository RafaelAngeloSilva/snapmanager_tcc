<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Documentos enviados para assinatura eletrônica (Clicksign).
 *
 * Nenhuma credencial vive aqui: só os IDs públicos dos recursos criados na
 * Clicksign (envelope/documento), que sozinhos não dão acesso a nada — toda
 * chamada à API exige o Access Token, que fica só no .env.
 *
 * `failed_step` + `last_error` guardam o suficiente para entender e limpar
 * um envio que quebrou no meio (ex.: envelope criado, signatário recusado),
 * sem apagar o registro local.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('clients')->restrictOnDelete();
            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->foreignId('appointment_id')->nullable()->constrained('appointments')->nullOnDelete();

            $table->string('title');
            $table->string('type');
            $table->string('status')->default('draft');
            $table->text('notes')->nullable();

            // Caminhos RELATIVOS no disco privado — nunca expostos na API.
            $table->string('original_file_path');
            $table->string('original_file_name');
            $table->unsignedInteger('original_file_size');
            $table->string('signed_file_path')->nullable();

            $table->string('clicksign_envelope_id')->nullable()->index();
            $table->string('clicksign_document_id')->nullable()->index();

            $table->timestamp('sent_at')->nullable();
            $table->timestamp('last_notified_at')->nullable();
            $table->timestamp('signed_at')->nullable();
            $table->date('expires_at')->nullable();

            $table->string('failed_step')->nullable();
            $table->text('last_error')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
