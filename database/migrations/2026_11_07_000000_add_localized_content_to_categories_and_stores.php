<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Arabic copy for merchant catalogue and shopfront content.
 *
 * One row keeps both languages rather than duplicating a category or a store per
 * locale: the record's identity (slug, hierarchy, currency, tenant ownership) is
 * the same document, and only its prose changes. That also means translating a
 * category cannot accidentally create a second URL or detach it from its
 * products.
 *
 * Every Arabic column is nullable. A missing translation is not an error — the
 * API falls back to the canonical English value rather than showing an empty
 * label. Numeric configuration, currency codes, timezones and credentials are
 * deliberately not localized.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->string('name_ar', 120)->nullable()->after('name');
            $table->text('description_ar')->nullable()->after('description');
        });

        Schema::table('stores', function (Blueprint $table) {
            $table->string('name_ar', 120)->nullable()->after('name');
            $table->text('description_ar')->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->dropColumn(['name_ar', 'description_ar']);
        });

        Schema::table('stores', function (Blueprint $table) {
            $table->dropColumn(['name_ar', 'description_ar']);
        });
    }
};
