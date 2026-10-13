<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Localize the public content pages.
     *
     * Each page (terms, privacy, about) becomes one row per language, so the
     * same structured document exists in English and Arabic and the public
     * endpoint can serve the one the visitor asked for. The `key` is no longer
     * unique on its own — the identity of a page is the pair (`key`, `locale`).
     */
    public function up(): void
    {
        Schema::table('content_pages', function (Blueprint $table) {
            $table->string('locale', 8)->default('en')->after('id');
        });

        Schema::table('content_pages', function (Blueprint $table) {
            $table->dropUnique('content_pages_key_unique');
            $table->unique(['key', 'locale']);
        });
    }

    public function down(): void
    {
        Schema::table('content_pages', function (Blueprint $table) {
            $table->dropUnique('content_pages_key_locale_unique');
            $table->unique('key');
        });

        Schema::table('content_pages', function (Blueprint $table) {
            $table->dropColumn('locale');
        });
    }
};
