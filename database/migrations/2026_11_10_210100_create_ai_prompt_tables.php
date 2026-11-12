<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Versioned prompt templates.
     *
     * A template belongs to one task; each edit creates a new immutable
     * version. The active version is a pointer, so rolling back is setting
     * the pointer back — no version row is ever edited or deleted. Variables
     * are whitelisted per version and interpolated server-side only.
     */
    public function up(): void
    {
        Schema::create('ai_prompt_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained('ai_tasks')->cascadeOnDelete();
            $table->string('key', 60);
            $table->string('name', 120);
            $table->text('description')->nullable();
            // Points at the version a request renders. The foreign key is
            // added after `ai_prompt_versions` exists below.
            $table->unsignedBigInteger('active_version_id')->nullable();
            $table->timestamps();

            $table->unique(['task_id', 'key']);
        });

        Schema::create('ai_prompt_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('template_id')->constrained('ai_prompt_templates')->cascadeOnDelete();
            $table->unsignedInteger('version');
            // BCP-47 style locale, e.g. `en`, `ar`.
            $table->string('locale', 10);
            $table->text('system_prompt');
            $table->text('user_prompt')->nullable();
            // list<string> of allowed template variables.
            $table->json('variables');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('change_note')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(['template_id', 'version']);
            $table->index(['template_id', 'locale']);
        });

        // The pointer column references a table created after it; add it now
        // that both exist.
        Schema::table('ai_prompt_templates', function (Blueprint $table) {
            $table
                ->foreign('active_version_id', 'ai_prompts_active_version_fk')
                ->references('id')
                ->on('ai_prompt_versions')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_prompt_versions');
        Schema::dropIfExists('ai_prompt_templates');
    }
};
