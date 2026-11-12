<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Backfill the canonical product copy into the review ledger.
 *
 * Before this change the ledger could only describe a product's name and
 * description. Adding short descriptions, bullets, specifications, tags and SEO
 * copy means existing products would otherwise appear "not translated" in the
 * new per-language editor even though their source copy is perfectly good.
 *
 * The rows are inserted as already-approved `legacy` copy in the platform's
 * default language. That is the copy the storefront was already serving, so
 * nothing about its visibility changes; the merchant simply gets a starting
 * point to translate from. Existing rows are never overwritten.
 */
return new class extends Migration
{
    public function up(): void
    {
        $locale = (string) config('dukavo.locales.default', 'en');
        $now = now();

        DB::table('products')
            ->select(['id', 'tenant_id', 'store_id', 'name', 'description', 'short_description', 'seo_title', 'seo_description', 'seo_keywords'])
            ->orderBy('id')
            ->chunkById(200, function ($products) use ($locale, $now): void {
                $rows = [];

                foreach ($products as $product) {
                    $fields = [
                        'name' => $product->name,
                        'description' => $product->description,
                        'short_description' => $product->short_description ?? null,
                        'seo_title' => $product->seo_title ?? null,
                        'seo_description' => $product->seo_description ?? null,
                        'seo_keywords' => $product->seo_keywords ?? null,
                    ];

                    foreach ($fields as $field => $text) {
                        if (! is_string($text) || trim($text) === '') {
                            continue;
                        }

                        $rows[] = [
                            'tenant_id' => $product->tenant_id,
                            'store_id' => $product->store_id,
                            'translatable_type' => 'product',
                            'translatable_id' => $product->id,
                            'locale' => $locale,
                            'field' => $field,
                            'source_locale' => $locale,
                            'source_text' => $text,
                            'source_hash' => hash('sha256', trim($text)),
                            'translated_text' => $text,
                            'published_text' => $text,
                            'ai_text' => null,
                            'status' => 'approved',
                            'origin' => 'legacy',
                            'manually_edited' => false,
                            'provider' => null,
                            'model' => null,
                            'ai_usage_id' => null,
                            'generated_at' => null,
                            'reviewed_at' => $now,
                            'reviewed_by' => null,
                            'rejection_reason' => null,
                            'metadata' => null,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                    }
                }

                if ($rows !== []) {
                    DB::table('translations')->insertOrIgnore($rows);
                }
            }, 'id');
    }

    public function down(): void
    {
        DB::table('translations')
            ->where('translatable_type', 'product')
            ->where('origin', 'legacy')
            ->delete();
    }
};
