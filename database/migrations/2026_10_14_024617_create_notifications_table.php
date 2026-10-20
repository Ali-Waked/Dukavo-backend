<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The in-app notification record.
 *
 * This is the authoritative copy every user sees in the bell and the centre.
 * It is deliberately independent of any outbound delivery: reading a
 * notification here says nothing about whether an email or WhatsApp message
 * was accepted, which is why delivery state lives in its own table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // The recipient. A `user` morph is an in-app recipient; a `customer`
            // morph is an outbound-only notification and appears in no user's
            // list. The morph map in AppServiceProvider constrains the values.
            $table->morphs('notifiable');

            // Null marks a platform-scoped notification. Tenant notifications
            // cascade away with their tenant so no orphan can outlive it.
            $table->foreignId('tenant_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('scope', 20);
            $table->string('event_key');

            $table->string('category', 40);
            $table->string('severity', 20)->default('info');
            $table->string('title');
            $table->text('body')->nullable();
            $table->string('action_url', 2048)->nullable();
            $table->json('data')->nullable();

            // A stable key identifying the logical event *for this recipient*.
            // The unique index below is what makes a retried business event
            // unable to create a duplicate row.
            $table->string('dedupe_key')->nullable();

            $table->timestamp('read_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->unique(['notifiable_type', 'notifiable_id', 'dedupe_key'], 'notifications_recipient_dedupe_unique');
            $table->index(['notifiable_type', 'notifiable_id', 'read_at'], 'notifications_recipient_read_index');
            $table->index(['notifiable_type', 'notifiable_id', 'created_at'], 'notifications_recipient_created_index');
            $table->index(['scope', 'tenant_id', 'created_at'], 'notifications_scope_tenant_index');
            $table->index('event_key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
