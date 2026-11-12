<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per tenant-owned connection to a communication provider.
     *
     * The conversation engine is channel agnostic; this table is the bridge
     * between a provider account (a Telegram bot, a Meta page, an SMTP identity)
     * and the tenant that owns it. Credentials are encrypted by the model cast
     * and never serialised. Adding a channel is a new connector, not a schema
     * change.
     */
    public function up(): void
    {
        Schema::create('channel_connections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->nullable()->constrained()->nullOnDelete();
            $table->string('channel', 40);
            $table->string('provider', 60);
            $table->string('name');
            $table->string('external_account_id')->nullable();
            $table->string('display_name')->nullable();
            $table->text('credentials')->nullable();
            $table->json('capabilities')->nullable();
            $table->string('status', 30)->default('pending');
            $table->string('health', 30)->default('unknown');
            $table->string('error_message', 500)->nullable();
            $table->timestamp('connected_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamp('last_health_check_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'channel', 'status'], 'channel_connections_tenant_channel_index');
            $table->index(['tenant_id', 'store_id'], 'channel_connections_tenant_store_index');
            $table->index(['channel', 'external_account_id'], 'channel_connections_external_index');
            $table->index(['channel', 'health'], 'channel_connections_health_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('channel_connections');
    }
};
