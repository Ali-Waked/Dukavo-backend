<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Proposed business actions, and the record of what happened to them.
     *
     * An AI assistant suggests; it never acts. A suggestion becomes a row here
     * in `draft`, is submitted for approval, and is then approved, rejected or
     * executed. The row is the separation between the two: a model can only
     * ever write a proposal, while only an authorised, idempotent executor
     * changes business data.
     *
     * `idempotency_key` is unique so the same logical action cannot be applied
     * twice, no matter how many times a retry or a duplicate submission
     * arrives.
     */
    public function up(): void
    {
        Schema::create('ai_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('use_case', 60);
            $table->string('action_type', 60);
            $table->string('status', 20)->default('draft');
            $table->string('summary', 255)->nullable();
            // What the model proposed, and what the server resolved it to.
            $table->json('payload')->nullable();
            $table->json('resolved_payload')->nullable();
            $table->json('result')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->text('error')->nullable();
            // The key that makes a repeated execution a no-op.
            $table->string('idempotency_key', 120)->nullable()->unique();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamp('executed_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index(['use_case', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_actions');
    }
};
