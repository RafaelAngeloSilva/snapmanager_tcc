<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Nullable timestamp instead of a separate "selection_status" enum —
     * "pending" vs. "completed" is fully derivable from whether this is
     * null, and a timestamp also gives the "concluído em" moment for free.
     * Updated on every confirmation (not just the first), per the chosen
     * rule: the client can keep changing their selection while the gallery
     * is active, and each confirmation moves this timestamp forward.
     */
    public function up(): void
    {
        Schema::table('galleries', function (Blueprint $table) {
            $table->dateTime('selection_completed_at')->nullable()->after('closed_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('galleries', function (Blueprint $table) {
            $table->dropColumn('selection_completed_at');
        });
    }
};
