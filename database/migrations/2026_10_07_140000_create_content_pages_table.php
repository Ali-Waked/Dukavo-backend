<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The long-form public pages a platform administrator maintains without a
     * frontend deployment: the terms, the privacy policy and the about page.
     *
     * Platform-level (no `tenant_id`). The structured body lives in a single
     * JSON column rather than a child table because it is a short, ordered list
     * of sections that is always read and written whole — a separate table
     * would add joins and transaction overhead for no query benefit.
     *
     * `published_at` is stored apart from `updated_at` on purpose: the public
     * "last updated" date must reflect when the content was published, not the
     * last time a draft was saved. `active` is the publication switch; a page
     * that is not active is a 404 on the public endpoint, never a blank policy.
     */
    public function up(): void
    {
        Schema::create('content_pages', function (Blueprint $table) {
            $table->id();
            $table->string('key', 50)->unique();
            $table->string('title', 200);
            $table->text('intro')->nullable();
            $table->json('sections');
            $table->boolean('active')->default(false);
            $table->timestamp('published_at')->nullable();
            $table->string('meta_title', 200)->nullable();
            $table->string('meta_description', 320)->nullable();
            $table->timestamps();

            $table->index(['active', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_pages');
    }
};
