<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The recipient ledger for a platform campaign.
 *
 * The unique (campaign, user) index is the duplicate-delivery guard: resolving
 * an audience twice can never queue the same merchant twice. Status is per
 * recipient so an operator can see exactly who was skipped for consent or a
 * missing device rather than reading aggregate counters.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_campaign_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained('notification_campaigns')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('notification_id')->nullable()->constrained('notifications')->nullOnDelete();

            $table->string('status', 20)->default('pending');
            $table->string('error')->nullable();
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();

            $table->unique(['campaign_id', 'user_id'], 'notification_campaign_recipients_unique');
            $table->index(['campaign_id', 'status'], 'notification_campaign_recipients_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_campaign_recipients');
    }
};
