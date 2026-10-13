<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Conversations are channel agnostic. `channel` plus `external_id` maps an
     * inbound message on WhatsApp/Instagram/etc. to a Dukavo conversation,
     * so adding a channel never requires changing this table.
     */
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->string('channel', 30)->default('whatsapp');
            // Opaque provider identifier (e.g. a WhatsApp phone_number_id) kept
            // as a plain string on purpose: conversations stay channel
            // agnostic so the next channel needs no schema change.
            $table->string('channel_account')->nullable();
            $table->string('external_id')->nullable();
            $table->string('subject')->nullable();
            $table->string('status', 20)->default('open');
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'channel', 'external_id']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'last_message_at']);
            $table->index(['assigned_to']);
            $table->index(['customer_id']);
            $table->index(['tenant_id', 'channel_account']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversations');
    }
};
