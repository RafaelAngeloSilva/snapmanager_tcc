<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Separa três conceitos que até aqui eram um só:
 *
 *   ORIGINAL            original_file_path (já existia) + original_file_type
 *                       — o arquivo que o funcionário enviou: PDF ou DOCX;
 *   PDF PARA ASSINATURA signing_file_path/size — o PDF convertido de um DOCX.
 *                       Para original PDF fica NULO: o próprio original é o
 *                       que se assina (sem cópia duplicada);
 *   ASSINADO            signed_file_path (já existia) — vindo da Clicksign.
 *
 * Os documentos existentes são todos PDF: o default 'pdf' os descreve
 * corretamente sem mover nem copiar arquivo nenhum.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->string('original_file_type', 10)->default('pdf')->after('original_file_size');
            $table->string('signing_file_path')->nullable()->after('original_file_type');
            $table->unsignedInteger('signing_file_size')->nullable()->after('signing_file_path');
            // Só para DOCX: converted | failed. Nulo para PDF (não se aplica).
            $table->string('conversion_status', 20)->nullable()->after('signing_file_size');
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropColumn(['original_file_type', 'signing_file_path', 'signing_file_size', 'conversion_status']);
        });
    }
};
