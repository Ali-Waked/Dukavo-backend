<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Customer-facing copy for a delivery rule, without duplicating the rule.
 *
 * A shipping zone is still one row per store, city and price. Only its prose
 * changes language. English remains the canonical column set because existing
 * rows already have `name`; every other locale is an entry in `translations`,
 * keyed by language code, so adding a third language never needs a migration.
 *
 * Fees, thresholds, city names, area identifiers, store ownership and the sort
 * order are deliberately not localized: they are operational facts, not copy,
 * and translating them would make two zones out of one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipping_zones', function (Blueprint $table) {
            $table->text('description')->nullable()->after('name');
            $table->string('method_name', 120)->nullable()->after('description');
            $table->text('method_description')->nullable()->after('method_name');
            $table->text('delivery_instructions')->nullable()->after('method_description');
            $table->text('terms')->nullable()->after('delivery_instructions');
            $table->json('translations')->nullable()->after('terms');
        });
    }

    public function down(): void
    {
        Schema::table('shipping_zones', function (Blueprint $table) {
            $table->dropColumn([
                'description',
                'method_name',
                'method_description',
                'delivery_instructions',
                'terms',
                'translations',
            ]);
        });
    }
};
