<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Delivery tracking for outbound messages.
     *
     * A support inbox has to distinguish "we sent it" from "the customer
     * received it" from "the customer read it", so provider webhooks write here
     * rather than trying to infer state from the message body.
     */
    public function up(): void
    {
        Schema::table('conversation_messages', function (Blueprint $table): void {
            $table->string('delivery_status', 20)->default('sent')->after('meta');
            $table->timestamp('delivered_at')->nullable()->after('delivery_status');
            $table->timestamp('read_at')->nullable()->after('delivered_at');

            $table->index(['delivery_status', 'sent_at'], 'conversation_messages_delivery_index');
        });
    }

    public function down(): void
    {
        Schema::table('conversation_messages', function (Blueprint $table): void {
            $table->dropIndex('conversation_messages_delivery_index');
            $table->dropColumn(['delivery_status', 'delivered_at', 'read_at']);
        });
    }
};
