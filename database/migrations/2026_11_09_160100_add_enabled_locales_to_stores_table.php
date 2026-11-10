<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The languages a storefront publishes.
 *
 * A store's enabled list is the only target set the translation assistant
 * offers. It is intentionally per store rather than per tenant: two outlets
 * may serve different audiences from one workspace. A NULL list means "use
 * the platform defaults", so existing stores keep behaving as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->json('enabled_locales')->nullable()->after('timezone');
        });
    }

    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->dropColumn('enabled_locales');
        });
    }
};
