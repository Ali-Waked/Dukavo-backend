<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Device metadata for Sanctum tokens.
     *
     * Sanctum records the token name and last use, but not where the token was
     * first minted. The security screen needs that to describe a device, and
     * storing it on the token keeps one session list without a second table.
     * Metadata is descriptive only; authorization still comes from the token.
     */
    public function up(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->char('country_code', 2)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->dropColumn(['ip_address', 'user_agent', 'country_code']);
        });
    }
};
