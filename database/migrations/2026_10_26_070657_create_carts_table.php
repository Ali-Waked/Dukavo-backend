<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A cart is the merchant-side order builder for one conversation.
     *
     * Carts never hold stock. They are a priced proposal until confirmation
     * converts them into an order, at which point the existing order service
     * reserves inventory under a row lock. `version` makes concurrent edits
     * explicit instead of silently overwriting a teammate's changes.
     */
    public function up(): void
    {
        Schema::create('carts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('order_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->string('status', 30)->default('draft');
            $table->string('currency', 3);
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('discount_total', 12, 2)->default(0);
            $table->decimal('tax_total', 12, 2)->default(0);
            $table->decimal('shipping_total', 12, 2)->default(0);
            $table->decimal('grand_total', 12, 2)->default(0);
            $table->decimal('tax_rate', 5, 4)->default(0);
            $table->json('shipping_address')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamp('confirmation_requested_at')->nullable();
            $table->timestamp('confirmation_sent_at')->nullable();
            $table->string('confirmation_message_id', 190)->nullable();
            $table->text('confirmation_error')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'conversation_id', 'created_at'], 'carts_conversation_index');
            $table->index(['tenant_id', 'status'], 'carts_status_index');
        });

        Schema::create('cart_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('cart_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->constrained()->cascadeOnDelete();
            $table->string('name', 255);
            $table->string('sku', 100)->nullable();
            $table->decimal('unit_price', 12, 2);
            $table->unsignedInteger('quantity');
            $table->decimal('discount_total', 12, 2)->default(0);
            $table->decimal('line_total', 12, 2);
            $table->timestamps();

            $table->unique(['cart_id', 'product_variant_id'], 'cart_items_variant_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cart_items');
        Schema::dropIfExists('carts');
    }
};
