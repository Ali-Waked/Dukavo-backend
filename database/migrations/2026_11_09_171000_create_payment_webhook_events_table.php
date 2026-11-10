<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The raw evidence of every provider callback.
     *
     * The payload is stored before it is acted on, so an operator can tell a
     * forged request from a valid one after the fact. The unique provider/event
     * pair makes redelivery idempotent: the second copy is acknowledged without
     * repeating the financial side effects.
     */
    public function up(): void
    {
        Schema::create('payment_webhook_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('provider_id')->nullable()->constrained('payment_providers')->nullOnDelete();
            $table->foreignId('store_payment_account_id')->nullable()->constrained('store_payment_accounts')->nullOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained('payments')->nullOnDelete();
            $table->foreignId('tenant_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('store_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event_id', 191)->nullable();
            $table->string('event_type', 80)->nullable();
            $table->string('status', 20)->default('received');
            $table->boolean('signature_valid')->default(false);
            $table->json('payload');
            $table->json('headers')->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->unique(['provider_id', 'event_id']);
            $table->index(['tenant_id', 'status']);
            $table->index(['payment_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_webhook_events');
    }
};
