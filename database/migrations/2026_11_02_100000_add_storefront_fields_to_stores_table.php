<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Storefront presentation and payment configuration live on the store.
 *
 * A store already owns its catalogue, inventory and currency, so it is the
 * correct owner for the public shopfront built from those records. The settings
 * column is deliberately JSON: these are presentation choices, not financial
 * data, and adding a new optional section must not require a destructive table
 * rewrite.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->text('description')->nullable()->after('slug');
            $table->string('logo_url', 500)->nullable()->after('description');
            $table->boolean('storefront_enabled')->default(false)->after('is_default');
            $table->json('storefront_settings')->nullable()->after('storefront_enabled');
            $table->json('payment_settings')->nullable()->after('storefront_settings');

            $table->index(['tenant_id', 'storefront_enabled']);
        });
    }

    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'storefront_enabled']);
            $table->dropColumn([
                'description',
                'logo_url',
                'storefront_enabled',
                'storefront_settings',
                'payment_settings',
            ]);
        });
    }
};
