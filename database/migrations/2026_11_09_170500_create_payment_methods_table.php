<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The customer-facing method catalogue (card, mada, KNet, Benefit, …).
     *
     * This is separate from the order-level intent (`cod`, `bank_transfer`,
     * `online`) and from the provider's own source id. Keeping all three
     * distinct is what lets the same order-level `online` intent resolve to a
     * different local rail per country without changing the order schema.
     */
    public function up(): void
    {
        Schema::create('payment_methods', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('name');
            $table->string('name_ar');
            $table->string('description');
            $table->string('description_ar');
            $table->string('kind', 20)->default('card');
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['is_active', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_methods');
    }
};
