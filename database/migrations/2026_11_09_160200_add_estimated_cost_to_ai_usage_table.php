<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The estimated provider charge for one AI call, in USD.
 *
 * Token counts alone do not tell a merchant what a translation batch cost, so
 * the ledger keeps a conservative estimate derived from the configured price
 * list. It is an estimate, never a billing record; an operator price change
 * only affects future rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_usage', function (Blueprint $table) {
            $table->decimal('estimated_cost', 12, 6)->nullable()->after('total_tokens');
        });
    }

    public function down(): void
    {
        Schema::table('ai_usage', function (Blueprint $table) {
            $table->dropColumn('estimated_cost');
        });
    }
};
