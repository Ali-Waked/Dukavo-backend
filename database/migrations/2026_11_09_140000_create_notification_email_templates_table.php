<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Platform-controlled overrides for the branded email catalogue.
 *
 * A row exists only after an operator saves a change. The catalogue itself
 * stays in code, so a new template ships without a migration and a restored
 * template simply deletes its override. Subjects, headings and intros are
 * stored per locale; unrestricted HTML is never accepted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_email_templates', function (Blueprint $table) {
            $table->id();
            $table->string('template_key', 120)->unique();
            $table->boolean('enabled')->default(true);
            $table->json('subject')->nullable();
            $table->json('heading')->nullable();
            $table->json('intro')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('updated_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_email_templates');
    }
};
