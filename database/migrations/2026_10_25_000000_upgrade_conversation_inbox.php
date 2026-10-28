<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Inbox maturity: unread state, provider message ids, internal notes,
     * retry lineage, media metadata and a small ledger for provider events.
     *
     * `external_id` remains the provider's message id. Caller supplied
     * idempotency keys move to `idempotency_key`, so a delivery receipt can
     * always be matched to the message Meta actually assigned an id to.
     */
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table): void {
            $table->unsignedInteger('unread_count')->default(0)->after('status');
            $table->timestamp('last_read_at')->nullable()->after('unread_count');
            $table->timestamp('last_inbound_at')->nullable()->after('last_read_at');
            $table->unsignedBigInteger('last_message_id')->nullable()->after('last_inbound_at');

            $table->index(['tenant_id', 'unread_count'], 'conversations_unread_index');
            $table->index(['tenant_id', 'last_inbound_at'], 'conversations_last_inbound_index');
        });

        Schema::table('conversation_messages', function (Blueprint $table): void {
            // Internal notes have no delivery lifecycle. Making the column
            // nullable keeps "never sent to a provider" distinct from "sent".
            $table->string('delivery_status', 20)->nullable()->change();
            $table->string('idempotency_key', 190)->nullable()->after('external_id');
            $table->boolean('is_internal')->default(false)->after('type');
            $table->string('failure_code', 80)->nullable()->after('read_at');
            $table->text('failure_reason')->nullable()->after('failure_code');
            $table->timestamp('failed_at')->nullable()->after('failure_reason');
            $table->foreignId('retry_of_id')->nullable()->after('failed_at')
                ->constrained('conversation_messages')->nullOnDelete();
            $table->unsignedInteger('attempt_count')->default(0)->after('retry_of_id');

            $table->unique(
                ['conversation_id', 'idempotency_key'],
                'conversation_messages_idempotency_unique',
            );
            $table->index(
                ['conversation_id', 'is_internal'],
                'conversation_messages_internal_index',
            );
            $table->index(['external_id'], 'conversation_messages_external_index');
        });

        Schema::create('channel_webhook_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 30);
            $table->string('event_key', 150);
            $table->string('event_type', 40);
            $table->string('payload_hash', 64)->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->text('failure')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'provider', 'event_key'], 'channel_webhook_events_unique');
            $table->index(['tenant_id', 'event_type', 'created_at'], 'channel_webhook_events_type_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('channel_webhook_events');

        Schema::table('conversation_messages', function (Blueprint $table): void {
            $table->dropUnique('conversation_messages_idempotency_unique');
            $table->dropIndex('conversation_messages_internal_index');
            $table->dropIndex('conversation_messages_external_index');
            $table->dropConstrainedForeignId('retry_of_id');
            $table->string('delivery_status', 20)->default('sent')->nullable(false)->change();
            $table->dropColumn([
                'idempotency_key',
                'is_internal',
                'failure_code',
                'failure_reason',
                'failed_at',
                'attempt_count',
            ]);
        });

        Schema::table('conversations', function (Blueprint $table): void {
            $table->dropIndex('conversations_unread_index');
            $table->dropIndex('conversations_last_inbound_index');
            $table->dropColumn(['unread_count', 'last_read_at', 'last_inbound_at', 'last_message_id']);
        });
    }
};
