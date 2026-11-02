<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Delivery pricing is store-scoped.
 *
 * A zone matches a city (or any city when NULL) and optionally a list of named
 * areas inside that city. Multiple zones may overlap: the cheapest matching
 * rule is chosen by the service, so ordering is explicit rather than implicit
 * in insertion order.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipping_zones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('city', 120)->nullable();
            $table->json('areas')->nullable();
            $table->decimal('fee', 12, 2)->default(0);
            $table->decimal('free_shipping_threshold', 12, 2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['tenant_id', 'store_id', 'is_active']);
            $table->index(['tenant_id', 'store_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipping_zones');
    }
};
