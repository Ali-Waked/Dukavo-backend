<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Store base-currency configuration and the conversion audit trail.
     *
     * A store's currency is no longer just a label: `currency_decimals` is the
     * minor-unit precision products are priced at, and `currency_updated_at`
     * records when the base currency last changed. The exchange rate, source and
     * timestamps of every change are kept in `currency_conversions` so a
     * historical report can explain — and reproduce — the rate that was used.
     *
     * The unique `idempotency_key` is what stops a retried request from
     * converting the same prices twice.
     */
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->unsignedTinyInteger('currency_decimals')->default(2)->after('currency');
            $table->timestamp('currency_updated_at')->nullable()->after('currency_decimals');
        });

        Schema::create('currency_conversions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->char('from_currency', 3);
            $table->char('to_currency', 3);
            $table->decimal('exchange_rate', 20, 10);
            $table->string('rate_source', 120);
            $table->timestamp('rate_fetched_at');
            $table->string('status', 20)->default('completed');
            $table->unsignedInteger('products_converted')->default(0);
            $table->unsignedInteger('variants_converted')->default(0);
            $table->unsignedInteger('bundles_converted')->default(0);
            $table->string('idempotency_key', 100)->unique();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('failure_reason')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['store_id', 'completed_at']);
            $table->index(['tenant_id', 'from_currency', 'to_currency']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('currency_conversions');

        Schema::table('stores', function (Blueprint $table) {
            $table->dropColumn(['currency_decimals', 'currency_updated_at']);
        });
    }
};
