<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('automation_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('automation_rule_id')->constrained()->cascadeOnDelete();
            $table->string('trigger', 60);
            $table->string('event_key', 191);
            $table->string('status', 20)->default('running');
            $table->boolean('matched')->default(false);
            $table->json('context')->nullable();
            $table->json('actions')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            // One rule may react to a given business event at most once, even
            // when a queue retries or an emitter dispatches twice.
            $table->unique(['automation_rule_id', 'event_key']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'trigger']);
            $table->index(['tenant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automation_runs');
    }
};
