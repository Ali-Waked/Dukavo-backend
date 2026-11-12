<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The Help Center: Dukavo's own product documentation.
     *
     * Platform-level (no `tenant_id`) content, like the FAQ and the newsletter
     * archive it sits beside. Articles are grouped into categories, tagged for
     * cross-links, and carry a visibility tier the server enforces on every
     * read path — public marketing pages see only `public` articles, signed-in
     * merchants see more, platform operators see everything.
     *
     * `body` holds sanitised HTML produced by the same `HtmlSanitizer` the
     * newsletter body passes through, so storing markup stays safe. Copy is
     * bilingual the same way a newsletter's is: canonical English columns plus
     * legacy `_ar` columns, with every other locale served from the approved
     * translation ledger. `localized_slugs` mirrors approved translated slugs
     * onto the row so URL resolution stays an indexed lookup.
     *
     * Feedback is privacy-conscious by design: the writer's IP is stored only
     * as a salted hash (never raw), used solely to stop duplicate votes, and the
     * comment is optional plain text. Search logs record the term and how many
     * results it found — never who typed it beyond a nullable user id — and are
     * pruned on a schedule.
     */
    public function up(): void
    {
        Schema::create('help_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('name_ar', 120)->nullable();
            $table->string('slug', 140)->unique();
            $table->string('description', 500)->nullable();
            $table->string('description_ar', 500)->nullable();
            // An icon key from the frontend's icon set, not a file path.
            $table->string('icon', 60)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_featured')->default(false);
            $table->timestamps();

            // The public Help Center always filters to active categories and
            // orders by the admin's chosen position.
            $table->index(['is_active', 'sort_order']);
        });

        Schema::create('help_articles', function (Blueprint $table) {
            $table->id();
            // A category with articles cannot be deleted — only archived — so
            // tidying the taxonomy can never silently destroy content. The
            // admin surface archives categories that still hold articles.
            $table->foreignId('category_id')->constrained('help_categories')->restrictOnDelete();
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title', 200);
            $table->string('title_ar', 200)->nullable();
            $table->string('slug', 200)->unique();
            $table->json('localized_slugs')->nullable();
            $table->string('excerpt', 320)->nullable();
            $table->string('excerpt_ar', 320)->nullable();
            $table->longText('body');
            $table->longText('body_ar')->nullable();
            $table->string('cover_image_path')->nullable();
            $table->string('cover_image_disk', 50)->nullable();
            // An optional screencast or explainer, rendered by the client as a
            // proper embed. The sanitiser strips <iframe> from bodies, so video
            // lives in its own validated https column instead.
            $table->string('video_url')->nullable();
            $table->string('status', 20)->default('draft');
            $table->string('visibility', 20)->default('public');
            $table->boolean('is_featured')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamp('published_at')->nullable();
            $table->unsignedInteger('view_count')->default(0);
            $table->unsignedInteger('helpful_count')->default(0);
            $table->unsignedInteger('unhelpful_count')->default(0);
            $table->string('meta_title', 200)->nullable();
            $table->string('meta_title_ar', 200)->nullable();
            $table->string('meta_description', 320)->nullable();
            $table->string('meta_description_ar', 320)->nullable();
            $table->string('meta_keywords', 500)->nullable();
            $table->timestamps();

            // The reader surfaces always filter to published articles inside an
            // allowed visibility set, then order within the category.
            $table->index(['status', 'visibility']);
            $table->index(['category_id', 'status', 'sort_order']);
            $table->index(['status', 'published_at']);
            $table->index('view_count');
        });

        Schema::create('help_tags', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80)->unique();
            $table->string('name_ar', 80)->nullable();
            $table->string('slug', 100)->unique();
            $table->timestamps();
        });

        Schema::create('help_article_tag', function (Blueprint $table) {
            $table->id();
            $table->foreignId('article_id')->constrained('help_articles')->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained('help_tags')->cascadeOnDelete();
            $table->unique(['article_id', 'tag_id']);
        });

        // Editor-curated "related reading" links. The pivot is ordered so an
        // author controls the reading sequence, and an article removed from the
        // center takes its links with it.
        Schema::create('help_article_related', function (Blueprint $table) {
            $table->id();
            $table->foreignId('article_id')->constrained('help_articles')->cascadeOnDelete();
            $table->foreignId('related_article_id')->constrained('help_articles')->cascadeOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $table->unique(['article_id', 'related_article_id']);
        });

        Schema::create('help_article_feedback', function (Blueprint $table) {
            $table->id();
            $table->foreignId('article_id')->constrained('help_articles')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            // A salted hash, never the address itself. NULLs are unique-tolerant
            // in MySQL, so a signed-in vote is deduplicated by user_id and an
            // anonymous one by ip_hash — the controller always sets one of them.
            $table->string('ip_hash', 64)->nullable();
            $table->boolean('helpful');
            $table->string('comment', 1000)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(['article_id', 'user_id'], 'help_feedback_article_user_unique');
            $table->unique(['article_id', 'ip_hash'], 'help_feedback_article_ip_unique');
            $table->index(['article_id', 'helpful']);
        });

        Schema::create('help_search_logs', function (Blueprint $table) {
            $table->id();
            $table->string('term', 200);
            $table->string('locale', 12);
            $table->unsignedInteger('results_count');
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index('created_at');
            $table->index(['results_count', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('help_search_logs');
        Schema::dropIfExists('help_article_feedback');
        Schema::dropIfExists('help_article_tag');
        Schema::dropIfExists('help_tags');
        Schema::dropIfExists('help_articles');
        Schema::dropIfExists('help_categories');
    }
};
