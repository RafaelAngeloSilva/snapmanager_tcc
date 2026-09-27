<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * "path" continua sendo o arquivo original, intocado — nunca
     * sobrescrito. "watermarked_path" é a versão derivada (com marca
     * d'água) usada na galeria pública; fica nula enquanto não é gerada
     * (ex.: marca d'água desativada na galeria, ou geração falhou).
     */
    public function up(): void
    {
        Schema::table('gallery_images', function (Blueprint $table) {
            $table->string('watermarked_path')->nullable()->after('path');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('gallery_images', function (Blueprint $table) {
            $table->dropColumn('watermarked_path');
        });
    }
};
