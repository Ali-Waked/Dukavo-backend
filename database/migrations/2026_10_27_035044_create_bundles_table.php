<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Merchant-curated bundles.
     *
     * A bundle is a priced collection, not an order and not a cart. It never
     * moves stock; availability is calculated from the catalogue at the moment
     * a merchant previews or adds it to a Smart Cart.
     */
    public function up(): void
    {
        Schema::create('bundles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            // A NULL store means the bundle can be offered wherever its
            // products are sold. A set store narrows it to that channel.
            $table->foreignId('store_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('status', 20)->default('draft');
            $table->string('discount_type', 20)->default('percentage');
            $table->decimal('discount_value', 12, 2)->default(0);
            $table->char('currency', 3)->default('USD');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'store_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bundles');
    }
};
