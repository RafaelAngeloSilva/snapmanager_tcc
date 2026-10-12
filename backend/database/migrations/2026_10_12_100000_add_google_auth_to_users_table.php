<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Login com Google:
 *  - google_id: o "sub" do Google (identificador estável da conta Google —
 *    o e-mail pode mudar, o sub não). UNIQUE: uma conta Google só pode estar
 *    vinculada a UM usuário do SnapManager. Nulo = conta sem Google.
 *  - google_linked_at: quando o vínculo foi feito (auditoria).
 *  - password passa a aceitar NULL: conta criada só pelo Google não tem
 *    senha local (em vez de uma senha aleatória que ninguém conhece). Login
 *    por senha com password NULL sempre falha (EloquentUserProvider).
 *
 * Usuários existentes não mudam: continuam com a senha deles e google_id nulo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('google_id', 64)->nullable()->unique()->after('email');
            $table->timestamp('google_linked_at')->nullable()->after('google_id');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('password')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Antes de exigir senha de novo, contas só-Google ficariam sem
        // senha válida: bloqueia o rollback em vez de inventar senhas.
        if (DB::table('users')->whereNull('password')->exists()) {
            throw new RuntimeException('Existem contas sem senha (criadas pelo Google). Defina uma senha para elas antes de reverter esta migration.');
        }

        Schema::table('users', function (Blueprint $table) {
            $table->string('password')->nullable(false)->change();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['google_id']);
            $table->dropColumn(['google_id', 'google_linked_at']);
        });
    }
};
