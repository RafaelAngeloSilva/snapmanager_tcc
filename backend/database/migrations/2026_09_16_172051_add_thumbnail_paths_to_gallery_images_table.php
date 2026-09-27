<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    /**
     * Miniaturas pequenas geradas no upload (ver GalleryController), pra
     * grade parar de baixar o arquivo original inteiro (até 10MB) só pra
     * exibir uma célula de ~150px. "thumbnail_path" (sem marca d'água) é
     * usado no painel interno; "watermarked_thumbnail_path" (com marca,
     * quando aplicável) é usado na grade da galeria pública. Fotos
     * enviadas antes desta migration ficam sem miniatura — os endpoints
     * que as servem caem de volta pro arquivo original/watermarked
     * existente, sem quebrar nada.
     */
    public function up(): void
    {
        Schema::table('gallery_images', function (Blueprint $table) {
            $table->string('thumbnail_path')->nullable()->after('watermarked_path');
            $table->string('watermarked_thumbnail_path')->nullable()->after('thumbnail_path');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('gallery_images', function (Blueprint $table) {
            $table->dropColumn(['thumbnail_path', 'watermarked_thumbnail_path']);
        });
    }
};
