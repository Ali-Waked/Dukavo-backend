<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fields a storefront order needs beyond the merchant-created order.
 *
 * `tracking_token` is the only public identifier a customer receives. It is a
 * random 64-character value rather than the sequential order number, so an
 * order cannot be enumerated. `idempotency_key` makes a retried checkout return
 * the original order instead of reserving stock twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('payment_method', 20)->nullable()->after('payment_status');
            $table->string('customer_email')->nullable()->after('customer_phone');
            $table->string('tracking_token', 64)->nullable()->unique()->after('shipping_address');
            $table->foreignId('shipping_zone_id')->nullable()->after('tracking_token')
                ->constrained('shipping_zones')->nullOnDelete();
            $table->string('idempotency_key', 100)->nullable()->after('shipping_zone_id');
            $table->string('source', 20)->default('merchant')->after('idempotency_key');

            $table->unique(['tenant_id', 'idempotency_key']);
            $table->index(['tenant_id', 'source']);
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropUnique(['tracking_token']);
            $table->dropForeign(['shipping_zone_id']);
            $table->dropUnique(['tenant_id', 'idempotency_key']);
            $table->dropIndex(['tenant_id', 'source']);
            $table->dropColumn([
                'payment_method',
                'customer_email',
                'tracking_token',
                'shipping_zone_id',
                'idempotency_key',
                'source',
            ]);
        });
    }
};
