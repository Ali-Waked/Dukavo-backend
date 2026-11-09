<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('automation_run_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('automation_run_id')->constrained()->cascadeOnDelete();
            $table->string('level', 20)->default('info');
            $table->string('message', 500);
            $table->json('context')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['automation_run_id', 'id']);
            $table->index(['tenant_id', 'level']);
        });

        Schema::table('automation_runs', function (Blueprint $table) {
            $table->unsignedTinyInteger('attempts')->default(1)->after('status');
            $table->string('source', 20)->default('event')->after('matched');
            $table->unsignedInteger('duration_ms')->nullable()->after('error');
            $table->timestamp('queued_at')->nullable()->after('started_at');
        });

        Schema::table('automation_tasks', function (Blueprint $table) {
            $table->string('dedupe_key', 191)->nullable()->after('completed_at');
            $table->unique(['tenant_id', 'dedupe_key'], 'automation_tasks_tenant_dedupe_unique');
        });
    }

    public function down(): void
    {
        Schema::table('automation_tasks', function (Blueprint $table) {
            $table->dropUnique('automation_tasks_tenant_dedupe_unique');
            $table->dropColumn('dedupe_key');
        });

        Schema::table('automation_runs', function (Blueprint $table) {
            $table->dropColumn(['attempts', 'source', 'duration_ms', 'queued_at']);
        });

        Schema::dropIfExists('automation_run_logs');
    }
};
