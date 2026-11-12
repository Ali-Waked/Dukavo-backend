<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('automation_template_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('automation_template_id')->constrained()->cascadeOnDelete();
            $table->string('version', 20);
            $table->string('name', 180);
            $table->text('description');
            $table->string('category', 40);
            $table->string('trigger', 60);
            $table->json('conditions')->nullable();
            $table->json('actions');
            $table->json('configuration_schema')->nullable();
            $table->string('checksum', 64);
            $table->text('changelog')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['automation_template_id', 'version'], 'automation_template_version_unique');
            $table->index('checksum');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automation_template_versions');
    }
};
