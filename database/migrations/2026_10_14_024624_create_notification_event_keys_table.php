<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Idempotency and re-alert bookkeeping.
 *
 * A row exists per logical event per recipient. For a one-shot event the row's
 * existence is enough to refuse a second dispatch forever; for a recurring
 * event (a low-stock alert) `last_notified_at` and the event's cooldown decide
 * whether enough time has passed to alert again. This is what stops a retried
 * job, a concurrent request, or a repeated observer call from spamming the
 * same user.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_event_keys', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('scope', 20);
            $table->timestamp('last_notified_at')->nullable();
            $table->unsignedInteger('notifications_count')->default(0);
            $table->timestamps();

            $table->index('scope');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_event_keys');
    }
};
