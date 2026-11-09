<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('automation_template_installations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('automation_template_id')->constrained()->cascadeOnDelete();
            $table->foreignId('automation_rule_id')->constrained()->cascadeOnDelete();
            $table->string('scope_key', 40);
            $table->string('installed_version', 20);
            $table->json('configuration')->nullable();
            $table->string('status', 20)->default('installed');
            $table->boolean('template_customized')->default(false);
            $table->foreignId('installed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('installed_at')->nullable();
            $table->timestamp('enabled_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            // `store_id` is nullable, which most databases allow to repeat in a
            // unique index. `scope_key` is the non-null discriminator that makes
            // a tenant-wide installation genuinely unique.
            $table->unique(['tenant_id', 'automation_template_id', 'scope_key'], 'automation_installations_scope_unique');
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'store_id']);
            $table->index('automation_rule_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automation_template_installations');
    }
};
