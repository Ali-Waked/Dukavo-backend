<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Delivery evidence for provider callbacks and permanent-failure handling.
 *
 * `failure_type` records whether a retry can ever succeed. `provider_payload`
 * keeps the webhook body that changed a delivery's state so a disputed bounce
 * can be investigated. `dedupe_key` gives account/security outbox rows the same
 * duplicate protection notification deliveries get from their notification id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notification_deliveries', function (Blueprint $table): void {
            $table->string('failure_type', 20)->nullable()->after('error');
            $table->json('provider_payload')->nullable()->after('provider_message_id');
            $table->string('dedupe_key')->nullable()->after('event_key');
            $table->string('template', 60)->nullable()->after('dedupe_key');
            $table->json('payload')->nullable()->after('template');

            $table->unique('dedupe_key', 'notification_deliveries_dedupe_key_unique');
            $table->index('provider_message_id', 'notification_deliveries_provider_message_id_index');
        });
    }

    public function down(): void
    {
        Schema::table('notification_deliveries', function (Blueprint $table): void {
            $table->dropUnique('notification_deliveries_dedupe_key_unique');
            $table->dropIndex('notification_deliveries_provider_message_id_index');
            $table->dropColumn(['failure_type', 'provider_payload', 'dedupe_key', 'template', 'payload']);
        });
    }
};
