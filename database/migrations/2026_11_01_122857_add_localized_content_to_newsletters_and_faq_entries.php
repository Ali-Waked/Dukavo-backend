<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Arabic copy for the public blog and FAQ.
     *
     * One row keeps both languages rather than duplicating an edition per
     * locale: the article's identity (slug, cover, publication state) is the
     * same document, and only its prose changes. That also means publishing a
     * translation cannot accidentally create a second URL or a second archive
     * entry.
     *
     * Every Arabic column is nullable. A missing translation is not an error —
     * the public API falls back to English rather than showing an empty article.
     */
    public function up(): void
    {
        Schema::table('newsletters', function (Blueprint $table) {
            $table->string('title_ar', 200)->nullable()->after('title');
            $table->text('excerpt_ar')->nullable()->after('excerpt');
            $table->longText('body_ar')->nullable()->after('body');
            $table->string('meta_title_ar', 200)->nullable()->after('meta_title');
            $table->string('meta_description_ar', 320)->nullable()->after('meta_description');
        });

        Schema::table('faq_entries', function (Blueprint $table) {
            $table->string('question_ar', 200)->nullable()->after('question');
            $table->text('answer_ar')->nullable()->after('answer');
        });
    }

    public function down(): void
    {
        Schema::table('newsletters', function (Blueprint $table) {
            $table->dropColumn([
                'title_ar',
                'excerpt_ar',
                'body_ar',
                'meta_title_ar',
                'meta_description_ar',
            ]);
        });

        Schema::table('faq_entries', function (Blueprint $table) {
            $table->dropColumn(['question_ar', 'answer_ar']);
        });
    }
};
