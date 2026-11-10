<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What an adapter can actually do. A refund button is only offered when
     * `refunds` is present; a missing row is a hard no rather than an assumption.
     */
    public function up(): void
    {
        Schema::create('payment_provider_capabilities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('provider_id')->constrained('payment_providers')->cascadeOnDelete();
            $table->string('capability', 40);
            $table->timestamps();

            $table->unique(['provider_id', 'capability']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_provider_capabilities');
    }
};
