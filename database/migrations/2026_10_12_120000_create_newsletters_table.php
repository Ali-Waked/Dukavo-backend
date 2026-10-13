<?php

declare(strict_types=1);

use App\Support\HtmlSanitizer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Newsletter editions: the long-form articles Dukavo publishes to the
     * people on the subscriber list and to the public blog archive.
     *
     * Platform-level (no `tenant_id`): a newsletter is Dukavo's own voice, not
     * a merchant's, so it is never scoped to a workspace. This is distinct from
     * `newsletter_subscribers`, which records who consented to receive one.
     *
     * `body` holds sanitised HTML, not JSON: a newsletter is prose with inline
     * emphasis and links, and it is always read and written whole. The sanitiser
     * (see {@see HtmlSanitizer}) is what makes storing markup safe.
     *
     * `published_at` is stored apart from `status` and `updated_at` so the
     * public "published" date reflects the moment the edition went live, not the
     * last time a draft was saved. `cover_image_path` is a key on a filesystem
     * disk, never a URL, so migrating storage never rewrites the table.
     */
    public function up(): void
    {
        Schema::create('newsletters', function (Blueprint $table) {
            $table->id();
            $table->string('title', 200);
            $table->string('slug', 200)->unique();
            $table->text('excerpt')->nullable();
            $table->longText('body');
            $table->string('cover_image_path')->nullable();
            $table->string('cover_image_disk', 50)->nullable();
            $table->string('status', 20)->default('draft');
            $table->timestamp('published_at')->nullable();
            $table->string('meta_title', 200)->nullable();
            $table->string('meta_description', 320)->nullable();
            $table->timestamps();

            // The public archive always filters to published editions and orders
            // by publication date, so both columns live in one index.
            $table->index(['status', 'published_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('newsletters');
    }
};
