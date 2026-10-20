<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per outbound delivery attempt.
 *
 * Delivery state is kept apart from the notification on purpose: an email can
 * fail while the in-app notification survives, and a user reading a
 * notification must never be misread as an email having been delivered. The
 * `recipient_ref` column stores only a masked destination (for example
 * `c•••@example.com`) so this table can be shown in an operations view without
 * exposing an address.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_deliveries', function (Blueprint $table) {
            $table->id();

            // Nullable so a delivery can record an attempt for an event whose
            // recipient never had an in-app copy (a customer order message).
            $table->uuid('notification_id')->nullable();
            $table->foreign('notification_id')->references('id')->on('notifications')->cascadeOnDelete();

            $table->string('event_key');
            $table->string('channel', 20);

            $table->string('recipient_type', 40);
            $table->unsignedBigInteger('recipient_id');
            $table->string('recipient_ref')->nullable();

            $table->string('status', 20)->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->string('provider_message_id')->nullable();
            $table->string('error')->nullable();

            $table->timestamp('queued_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();

            // A notification has at most one delivery per channel, so a retried
            // dispatch cannot fan out a second send for the same channel.
            $table->unique(['notification_id', 'channel'], 'notification_deliveries_notification_channel_unique');
            $table->index(['channel', 'status'], 'notification_deliveries_channel_status_index');
            $table->index(['event_key', 'status'], 'notification_deliveries_event_status_index');
            $table->index(['recipient_type', 'recipient_id'], 'notification_deliveries_recipient_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_deliveries');
    }
};
