<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-attempt operational logging and the columns usage accounting grew.
     *
     * `ai_activity_logs` records every provider attempt — including the
     * attempts a fallback abandoned — with the correlation id that ties them
     * together. Only metadata travels here: no prompt, no answer, no customer
     * content and no credential ever reaches this table.
     *
     * The `ai_usage` extensions keep the existing ledger shape (rows keep
     * being append-only and keyed by feature) while adding the task the call
     * served, the correlation id, how many fallback attempts preceded the
     * answer, and whether the answer came from cache.
     */
    public function up(): void
    {
        Schema::create('ai_activity_logs', function (Blueprint $table) {
            $table->id();
            // One id per logical request; every attempt shares it.
            $table->string('correlation_id', 40)->index();
            $table->foreignId('tenant_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            // AiTask::value
            $table->string('task', 60);
            $table->string('feature', 40)->nullable();
            $table->string('provider', 40);
            $table->string('model', 120)->nullable();
            // `success` or `failed` for this attempt.
            $table->string('status', 20);
            // AiErrorCategory::value, when the attempt failed.
            $table->string('error_category', 40)->nullable();
            $table->unsignedInteger('http_status')->nullable();
            $table->unsignedInteger('attempt')->default(1);
            $table->unsignedInteger('fallback_attempts')->default(0);
            $table->unsignedInteger('duration_ms')->default(0);
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->boolean('cache_hit')->default(false);
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['tenant_id', 'created_at']);
            $table->index(['task', 'created_at']);
            $table->index(['provider', 'created_at']);
            $table->index(['status', 'created_at']);
        });

        Schema::table('ai_usage', function (Blueprint $table) {
            $table->string('task', 60)->nullable()->after('feature');
            $table->string('correlation_id', 40)->nullable()->after('task');
            $table->unsignedInteger('fallback_attempts')->default(0)->after('total_tokens');
            $table->string('error_category', 40)->nullable()->after('status');
            $table->boolean('cache_hit')->default(false)->after('estimated_cost');

            $table->index(['task', 'created_at']);
            $table->index(['provider', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('ai_usage', function (Blueprint $table) {
            $table->dropIndex(['task', 'created_at']);
            $table->dropIndex(['provider', 'created_at']);
            $table->dropColumn(['task', 'correlation_id', 'fallback_attempts', 'error_category', 'cache_hit']);
        });

        Schema::dropIfExists('ai_activity_logs');
    }
};
