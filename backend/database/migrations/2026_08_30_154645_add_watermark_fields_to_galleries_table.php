<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('galleries', function (Blueprint $table) {
            $table->boolean('watermark_enabled')->default(true)->after('status');
            // "none" existe pra permitir desligar sem perder o texto/imagem
            // configurados, caso o funcionário reative depois.
            $table->string('watermark_type')->default('text')->after('watermark_enabled');
            $table->string('watermark_text')->nullable()->default('PROIBIDO REPRODUÇÃO')->after('watermark_type');
            $table->string('watermark_image_path')->nullable()->after('watermark_text');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('galleries', function (Blueprint $table) {
            $table->dropColumn(['watermark_enabled', 'watermark_type', 'watermark_text', 'watermark_image_path']);
        });
    }
};
