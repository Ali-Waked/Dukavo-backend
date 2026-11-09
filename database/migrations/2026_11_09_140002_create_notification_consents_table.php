<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only consent history for optional notification categories.
 *
 * Preferences say what an account wants *now*; this table says how that choice
 * was made and when. It is never updated in place, so a later opt-out cannot
 * erase the record of the earlier opt-in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_consents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('email')->nullable();
            $table->string('channel', 20);
            $table->string('category', 40);
            $table->string('action', 20);
            $table->string('source', 40);
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'category', 'channel'], 'notification_consents_owner_index');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_consents');
    }
};
