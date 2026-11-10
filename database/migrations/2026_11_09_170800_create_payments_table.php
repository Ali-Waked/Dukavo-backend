<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One online payment attempt.
     *
     * Immutable money facts are captured here — amount, currency, the provider
     * reference and the client reference sent to the provider. A retry adds a
     * new row; it never rewrites a failed attempt, so the history always shows
     * what was asked and what the provider answered.
     */
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('provider_id')->nullable()->constrained('payment_providers')->nullOnDelete();
            $table->foreignId('store_payment_account_id')->nullable()->constrained('store_payment_accounts')->nullOnDelete();
            $table->unsignedInteger('attempt_number')->default(1);
            $table->string('status', 30)->default('pending');
            $table->string('payment_method_code', 40)->nullable();
            $table->decimal('amount', 18, 4);
            $table->char('currency', 3);
            $table->string('client_reference', 64)->unique();
            $table->string('provider_reference')->nullable();
            $table->text('checkout_url')->nullable();
            $table->text('failure_reason')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->unique(['order_id', 'attempt_number']);
            $table->index(['tenant_id', 'status']);
            $table->index(['provider_id', 'provider_reference']);
            $table->index(['store_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
