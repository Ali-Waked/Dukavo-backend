<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A refund request against one successful charge.
     *
     * The amount is bounded by the original charge minus earlier successful
     * refunds, and the provider capability is checked in the same service
     * transaction. A refund row is never created for a charge that was not
     * actually captured.
     */
    public function up(): void
    {
        Schema::create('payment_refunds', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payment_id')->constrained('payments')->cascadeOnDelete();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('provider_id')->nullable()->constrained('payment_providers')->nullOnDelete();
            $table->foreignId('store_payment_account_id')->nullable()->constrained('store_payment_accounts')->nullOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 20)->default('pending');
            $table->decimal('amount', 18, 4);
            $table->char('currency', 3);
            $table->text('reason')->nullable();
            $table->string('provider_refund_id')->nullable();
            $table->string('idempotency_key', 191)->unique();
            $table->text('failure_reason')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index(['payment_id', 'status']);
            $table->index(['provider_id', 'provider_refund_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_refunds');
    }
};
