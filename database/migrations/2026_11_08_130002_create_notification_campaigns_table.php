<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A platform announcement managed by Dukavo staff.
 *
 * The campaign is the intent and its progress; `notification_campaign_recipients`
 * is the per-user ledger. A one-row campaign cannot tell an operator which
 * merchant was skipped for missing consent, so both exist.
 *
 * `audience` is JSON because targeting evolves (all, named tenants, named
 * users, excluded users) and every variant is an immutable snapshot at publish
 * time. Important queryable state — status, schedule, counters — stays in real
 * columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_campaigns', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('title');
            $table->text('body');
            $table->string('category', 40)->default('announcements');
            $table->string('severity', 20)->default('info');
            $table->string('priority', 20)->default('normal');
            $table->string('action_url', 2048)->nullable();

            // Selected channels: mail, push, in_app.
            $table->json('channels');
            $table->json('audience');
            $table->boolean('is_marketing')->default(false);

            $table->string('status', 20)->default('draft');
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->unsignedInteger('total_recipients')->default(0);
            $table->unsignedInteger('queued_count')->default(0);
            $table->unsignedInteger('sent_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->unsignedInteger('skipped_count')->default(0);

            // A client-supplied idempotency key stops a double-clicked publish
            // from producing two campaigns.
            $table->string('idempotency_key', 191)->nullable()->unique();
            $table->timestamps();

            $table->index(['status', 'scheduled_at'], 'notification_campaigns_status_schedule_index');
            $table->index('created_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_campaigns');
    }
};
