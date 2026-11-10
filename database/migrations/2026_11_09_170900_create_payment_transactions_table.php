<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The immutable financial ledger for provider calls.
     *
     * A row is written for every charge or refund attempt, including failures.
     * There is no `updated_at` and no soft delete: correcting a wrong provider
     * answer means adding a new transaction, not editing history.
     */
    public function up(): void
    {
        Schema::create('payment_transactions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payment_id')->constrained('payments')->cascadeOnDelete();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('provider_id')->nullable()->constrained('payment_providers')->nullOnDelete();
            $table->string('type', 20);
            $table->string('status', 20);
            $table->decimal('amount', 18, 4);
            $table->char('currency', 3);
            $table->string('provider_transaction_id')->nullable();
            $table->string('provider_status')->nullable();
            $table->string('idempotency_key', 191)->unique();
            $table->json('request_payload')->nullable();
            $table->json('response_payload')->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['tenant_id', 'type', 'status']);
            $table->index(['provider_id', 'provider_transaction_id']);
            $table->index(['payment_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_transactions');
    }
};
