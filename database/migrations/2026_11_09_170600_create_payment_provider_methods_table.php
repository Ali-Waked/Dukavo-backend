<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Maps a customer-facing method to the provider's source id in a country.
     *
     * `country_code` is nullable for a method the provider offers everywhere it
     * operates; a row with a country is an additional local rail. The source id
     * is provider configuration, never customer input.
     */
    public function up(): void
    {
        Schema::create('payment_provider_methods', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('provider_id')->constrained('payment_providers')->cascadeOnDelete();
            $table->string('method_code', 40);
            $table->char('country_code', 2)->nullable();
            $table->string('source_id', 80);
            $table->unsignedSmallInteger('priority')->default(0);
            $table->boolean('is_active')->default(true);
            $table->json('config')->nullable();
            $table->timestamps();

            $table->foreign('method_code')->references('code')->on('payment_methods')->cascadeOnUpdate();
            $table->foreign('country_code')->references('code')->on('payment_countries')->cascadeOnUpdate();
            $table->index(['provider_id', 'country_code', 'is_active'], 'payment_provider_method_active_index');
            $table->unique(['provider_id', 'method_code', 'country_code', 'source_id'], 'provider_method_country_source_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_provider_methods');
    }
};
