<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_signers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained('documents')->cascadeOnDelete();
            $table->string('name');
            $table->string('email')->nullable();
            // Só dígitos, DDD + número, sem DDI — formato exigido pela Clicksign.
            $table->string('phone_number', 11)->nullable();
            $table->string('channel');
            $table->string('clicksign_signer_id')->nullable()->index();
            $table->string('status')->default('pending');
            $table->timestamp('signed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_signers');
    }
};
