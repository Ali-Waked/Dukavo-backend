<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('automation_installation_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('automation_template_installation_id')
                ->constrained('automation_template_installations', indexName: 'automation_installation_events_installation_fk')
                ->cascadeOnDelete();
            $table->string('type', 40);
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['tenant_id', 'type']);
            $table->index('automation_template_installation_id', 'automation_installation_events_installation_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automation_installation_events');
    }
};
