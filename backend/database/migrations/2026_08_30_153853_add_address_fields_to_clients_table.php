<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Substitui o campo único "address" (texto livre) por endereço
     * estruturado — necessário para o preenchimento automático via CEP e
     * para validar cada parte do endereço separadamente. Migration nova (não
     * mexe na migration antiga de "clients"); o "address" existente é
     * descartado porque era só texto livre sem estrutura para migrar campo a
     * campo, e não há dado de produção real em jogo (projeto de TCC).
     */
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn('address');

            $table->string('zip_code', 9)->nullable()->after('document');
            $table->string('street')->nullable()->after('zip_code');
            $table->string('number', 20)->nullable()->after('street');
            $table->string('complement')->nullable()->after('number');
            $table->string('neighborhood')->nullable()->after('complement');
            $table->string('city')->nullable()->after('neighborhood');
            $table->string('state', 2)->nullable()->after('city');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn(['zip_code', 'street', 'number', 'complement', 'neighborhood', 'city', 'state']);
            $table->string('address')->nullable();
        });
    }
};
