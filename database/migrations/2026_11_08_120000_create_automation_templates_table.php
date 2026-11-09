<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('automation_templates', function (Blueprint $table) {
            $table->id();
            $table->string('key', 120)->unique();
            $table->string('name', 180);
            $table->text('description');
            $table->string('category', 40)->default('general');
            $table->string('trigger', 60);
            $table->json('conditions')->nullable();
            $table->json('actions');
            $table->json('configuration_schema')->nullable();
            $table->string('icon', 60)->nullable();
            $table->string('status', 20)->default('draft');
            $table->string('current_version', 20)->default('1.0.0');
            $table->unsignedInteger('sort_order')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'category']);
            $table->index(['status', 'sort_order']);
            $table->index('trigger');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automation_templates');
    }
};
