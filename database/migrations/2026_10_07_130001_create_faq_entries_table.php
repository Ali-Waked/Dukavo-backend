<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Frequently asked questions for the marketing site.
     *
     * Platform-level (no `tenant_id`). Deliberately minimal — question, answer,
     * optional grouping and an ordering column — rather than a general purpose
     * CMS: the FAQ is the only long-form content the public API serves today.
     */
    public function up(): void
    {
        Schema::create('faq_entries', function (Blueprint $table) {
            $table->id();
            $table->string('question', 200);
            $table->text('answer');
            $table->string('category', 80)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index(['active', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('faq_entries');
    }
};
