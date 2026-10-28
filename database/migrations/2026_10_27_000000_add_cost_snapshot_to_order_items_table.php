<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Preserve the unit cost that applied when an order line was priced.
 *
 * Product and variant costs are mutable catalogue data. Reading them at report
 * time would silently rewrite the cost of historic sales whenever a merchant
 * updates a supplier price. These nullable columns are deliberately not
 * backfilled: a missing snapshot means "unknown", not "use today's cost".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->decimal('unit_cost', 12, 2)->nullable()->after('unit_price');
            $table->string('cost_currency', 3)->nullable()->after('unit_cost');
            $table->timestamp('cost_captured_at')->nullable()->after('cost_currency');
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn(['unit_cost', 'cost_currency', 'cost_captured_at']);
        });
    }
};
