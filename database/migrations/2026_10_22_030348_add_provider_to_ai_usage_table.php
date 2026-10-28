<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Record which provider served each call.
     *
     * Cost and reliability questions ("who spent the money", "which provider
     * was failing last week") cannot be answered without it once more than one
     * provider is configured. The column is additive and nullable so existing
     * rows keep their meaning.
     */
    public function up(): void
    {
        Schema::table('ai_usage', function (Blueprint $table) {
            $table->string('provider', 40)->nullable()->after('feature');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ai_usage', function (Blueprint $table) {
            $table->dropColumn('provider');
        });
    }
};
