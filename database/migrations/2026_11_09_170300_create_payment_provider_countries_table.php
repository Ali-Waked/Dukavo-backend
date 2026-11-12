<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Documented provider coverage per country.
     *
     * `merchant_onboarding` and `transaction_capable` are deliberately separate:
     * a provider can process transactions for merchants it no longer accepts
     * (Tap in Egypt and Jordan), and conflating the two would either block
     * existing merchants or wrongly invite new ones in a market the provider
     * has closed.
     */
    public function up(): void
    {
        Schema::create('payment_provider_countries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('provider_id')->constrained('payment_providers')->cascadeOnDelete();
            $table->char('country_code', 2);
            $table->char('settlement_currency', 3);
            $table->boolean('merchant_onboarding')->default(false);
            $table->boolean('transaction_capable')->default(false);
            $table->string('source_url')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();

            $table->foreign('country_code')->references('code')->on('payment_countries')->cascadeOnUpdate();
            $table->unique(['provider_id', 'country_code']);
            $table->index(['country_code', 'merchant_onboarding'], 'payment_provider_country_onboarding_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_provider_countries');
    }
};
