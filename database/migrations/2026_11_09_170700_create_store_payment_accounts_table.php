<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A merchant's connection to one provider for one store.
     *
     * Credentials are ciphertext (`encrypted` cast), hidden from serialisation
     * and never returned by any endpoint. A connection stays `pending` until a
     * provider-side test succeeds; availability never trusts a merchant's claim
     * that a key works.
     */
    public function up(): void
    {
        Schema::create('store_payment_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('provider_id')->constrained('payment_providers')->cascadeOnDelete();
            $table->string('status', 20)->default('pending');
            $table->string('environment', 20)->default('sandbox');
            $table->text('credentials');
            $table->text('webhook_secret')->nullable();
            $table->string('merchant_reference')->nullable();
            $table->json('settings')->nullable();
            $table->timestamp('last_verified_at')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['store_id', 'provider_id', 'environment'], 'store_provider_environment_unique');
            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_payment_accounts');
    }
};
