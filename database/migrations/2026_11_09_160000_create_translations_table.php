<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One reviewable translation per entity field and locale.
 *
 * The row is deliberately independent of any single model's legacy `_ar`
 * columns: it is the review ledger for every translatable record. Approved
 * rows are mirrored into the legacy storage where one exists, so existing
 * storefront reads keep working while the review state stays explicit.
 *
 * `tenant_id` is nullable because platform content (marketing FAQ, blog
 * editions, legal pages) has no workspace. Tenant rows are always filtered by
 * the resolved tenant in the service layer; the public read path only ever
 * resolves approved rows for the record it is already serving.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->nullable()->constrained()->nullOnDelete();

            $table->string('translatable_type', 100);
            $table->unsignedBigInteger('translatable_id');
            $table->string('locale', 12);
            $table->string('field', 80);

            $table->string('source_locale', 12);
            $table->text('source_text')->nullable();
            $table->string('source_hash', 64)->nullable();

            $table->longText('translated_text')->nullable();
            $table->longText('published_text')->nullable();
            $table->longText('ai_text')->nullable();
            $table->string('status', 24)->default('not_translated');
            $table->string('origin', 16)->default('ai');
            $table->boolean('manually_edited')->default(false);

            $table->string('provider', 40)->nullable();
            $table->string('model', 120)->nullable();
            $table->unsignedBigInteger('ai_usage_id')->nullable();

            $table->timestamp('generated_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('rejection_reason', 255)->nullable();
            $table->json('metadata')->nullable();

            $table->timestamps();

            $table->unique(['translatable_type', 'translatable_id', 'locale', 'field'], 'translations_record_locale_field_unique');
            $table->index(['tenant_id', 'store_id', 'status'], 'translations_tenant_store_status_index');
            $table->index(['translatable_type', 'translatable_id', 'locale'], 'translations_record_locale_index');
            $table->index(['status', 'locale'], 'translations_status_locale_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('translations');
    }
};
