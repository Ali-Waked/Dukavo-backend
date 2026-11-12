<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Localizable catalogue copy and per-store default language.
 *
 * The canonical columns stay on the product: a product is one record with one
 * price, one SKU and one stock position. Only its prose is localized, and the
 * approved values live in the shared `translations` review ledger. The JSON
 * columns here hold the canonical (source-language) lists a translation is
 * derived from, plus the localized slug map which is identity-adjacent and
 * therefore stored with the product.
 *
 * Every column is nullable and additive. Existing rows keep working unchanged
 * and no column is dropped.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->text('short_description')->nullable()->after('description');
            $table->json('highlights')->nullable()->after('short_description');
            $table->json('features')->nullable()->after('highlights');
            $table->json('specifications')->nullable()->after('features');
            $table->json('tags')->nullable()->after('specifications');
            $table->string('seo_title', 255)->nullable()->after('tags');
            $table->text('seo_description')->nullable()->after('seo_title');
            $table->string('seo_keywords', 500)->nullable()->after('seo_description');
            $table->json('localized_slugs')->nullable()->after('seo_keywords');
        });

        Schema::table('product_variants', function (Blueprint $table): void {
            $table->text('description')->nullable()->after('name');
        });

        Schema::table('product_images', function (Blueprint $table): void {
            $table->string('caption', 255)->nullable()->after('alt_text');
        });

        Schema::table('stores', function (Blueprint $table): void {
            $table->string('default_locale', 12)->nullable()->after('enabled_locales');
        });
    }

    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table): void {
            $table->dropColumn('default_locale');
        });

        Schema::table('product_images', function (Blueprint $table): void {
            $table->dropColumn('caption');
        });

        Schema::table('product_variants', function (Blueprint $table): void {
            $table->dropColumn('description');
        });

        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn([
                'short_description',
                'highlights',
                'features',
                'specifications',
                'tags',
                'seo_title',
                'seo_description',
                'seo_keywords',
                'localized_slugs',
            ]);
        });
    }
};
