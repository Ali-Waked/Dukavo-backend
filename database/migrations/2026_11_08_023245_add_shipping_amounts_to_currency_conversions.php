<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Delivery pricing belongs to the store's catalogue.
     *
     * A zone fee is a current amount, not a historical transaction, so changing
     * the store currency must convert it alongside product prices. The count is
     * recorded on the conversion receipt so the merchant can see what moved.
     *
     * `fee` and `free_shipping_threshold` are widened to four decimals for the
     * same reason as the other monetary columns: a three-decimal currency must
     * survive the conversion without a second rounding.
     */
    public function up(): void
    {
        Schema::table('shipping_zones', function (Blueprint $table) {
            $table->decimal('fee', 18, 4)->default(0)->change();
            $table->decimal('free_shipping_threshold', 18, 4)->nullable()->change();
        });

        Schema::table('currency_conversions', function (Blueprint $table) {
            $table->unsignedInteger('shipping_zones_converted')->default(0)->after('bundles_converted');
        });
    }

    public function down(): void
    {
        Schema::table('currency_conversions', function (Blueprint $table) {
            $table->dropColumn('shipping_zones_converted');
        });

        Schema::table('shipping_zones', function (Blueprint $table) {
            $table->decimal('fee', 12, 2)->default(0)->change();
            $table->decimal('free_shipping_threshold', 12, 2)->nullable()->change();
        });
    }
};
