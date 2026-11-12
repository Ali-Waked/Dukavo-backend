<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An append-only history of every change to a reviewable translation.
 *
 * The `translations` row is the current state; this table is what makes
 * "regenerate" and "edit" safe to offer. A merchant can always explain what a
 * translation said before an AI draft replaced it, and an approval cannot erase
 * the copy that was rejected before it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('translation_revisions', function (Blueprint $table): void {
            $table->id();
            // Kept even if the translation row itself is deleted so the audit
            // trail survives a record being removed.
            $table->unsignedBigInteger('translation_id');
            $table->foreignId('tenant_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('locale', 12);
            $table->string('field', 80);
            $table->longText('old_text')->nullable();
            $table->longText('new_text')->nullable();
            $table->string('old_status', 24)->nullable();
            $table->string('new_status', 24)->nullable();
            $table->string('origin', 16)->nullable();
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['translation_id', 'created_at'], 'translation_revisions_translation_created_index');
            $table->index(['tenant_id', 'locale'], 'translation_revisions_tenant_locale_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('translation_revisions');
    }
};
