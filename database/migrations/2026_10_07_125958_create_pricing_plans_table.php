<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Configurable pricing plans for the marketing site.
     *
     * Platform-level (no `tenant_id`). `price` is nullable on purpose: a NULL
     * means "not published yet" and the public API surfaces it as null so the
     * frontend can honestly render "Coming soon" instead of an invented number.
     * `features` and `limits` are JSON lists/maps of marketing copy, not billing
     * logic — the product has no billing engine yet and this table must not
     * pretend otherwise.
     */
    public function up(): void
    {
        Schema::create('pricing_plans', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('slug', 120)->unique();
            $table->string('description', 500)->nullable();
            $table->decimal('price', 12, 2)->nullable();
            $table->string('billing_interval', 20)->default('monthly');
            $table->string('currency', 3)->default('USD');
            $table->json('features')->nullable();
            $table->json('limits')->nullable();
            $table->boolean('highlighted')->default(false);
            $table->boolean('active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['active', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pricing_plans');
    }
};
