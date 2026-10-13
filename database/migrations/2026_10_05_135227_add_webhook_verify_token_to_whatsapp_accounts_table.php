<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Meta's webhook handshake requires the app to compare a shared verify token
     * against the one configured in the Meta dashboard. The column was missing,
     * which meant the handshake could never succeed.
     *
     * The value is a random string compared with an equality check, so it is
     * indexed rather than encrypted: an encrypted column cannot be looked up, and
     * the webhook has to find the account with no tenant context in hand.
     */
    public function up(): void
    {
        Schema::table('whatsapp_accounts', function (Blueprint $table): void {
            $table->string('webhook_verify_token', 190)->nullable()->after('access_token');
            $table->index('webhook_verify_token');
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_accounts', function (Blueprint $table): void {
            $table->dropIndex(['webhook_verify_token']);
            $table->dropColumn('webhook_verify_token');
        });
    }
};
