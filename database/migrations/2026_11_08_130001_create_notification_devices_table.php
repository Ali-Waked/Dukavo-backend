<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per installation that can receive a push.
 *
 * A user may sign in on several phones and tablets, so tokens are never stored
 * on the user. The token itself can be long and is not queried by equality, so
 * a SHA-256 `token_hash` carries the unique index while the token stays in a
 * text column. Revoked rows are kept for a while: they explain why a device
 * stopped receiving notifications and stop a stale client from resurrecting a
 * token that Firebase already rejected.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // App-generated installation id (stable across token rotation).
            $table->string('device_id', 191)->nullable();
            $table->string('platform', 20)->default('unknown');
            $table->text('token');
            $table->char('token_hash', 64)->unique();
            $table->string('device_name')->nullable();
            $table->string('app_version', 40)->nullable();
            $table->string('locale', 12)->nullable();

            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'revoked_at'], 'notification_devices_user_active_index');
            $table->index(['user_id', 'device_id'], 'notification_devices_user_device_index');
            $table->index(['platform', 'last_seen_at'], 'notification_devices_platform_seen_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_devices');
    }
};
