<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('module', 50);
            $table->string('description')->nullable();
            $table->timestamps();

            // Permission lists are almost always filtered or grouped by module.
            $table->index(['module']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('permissions');
    }
};
