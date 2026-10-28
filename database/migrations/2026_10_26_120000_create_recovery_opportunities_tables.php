<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Lost-sale opportunities and their funnel events.
     *
     * An opportunity is a reviewable record derived from a real cart or an
     * unanswered inbound message. Recovered revenue is never stored as a number
     * the app made up: it is read from the linked order, excluding cancellations.
     */
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->timestamp('whatsapp_opt_out_at')->nullable()->after('status');
            $table->index(['tenant_id', 'whatsapp_opt_out_at'], 'customers_whatsapp_opt_out_index');
        });

        Schema::create('recovery_opportunities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('cart_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('order_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('dismissed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 30)->default('eligible');
            $table->string('reason', 40);
            $table->string('dedupe_key', 190);
            $table->decimal('estimated_value', 12, 2)->nullable();
            $table->char('currency', 3)->nullable();
            $table->unsignedInteger('contact_attempts')->default(0);
            $table->timestamp('detected_at');
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamp('last_contacted_at')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->timestamp('next_follow_up_at')->nullable();
            $table->timestamp('recovered_at')->nullable();
            $table->timestamp('dismissed_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->string('dismissal_reason', 500)->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'dedupe_key'], 'recovery_opportunities_dedupe_unique');
            $table->index(['tenant_id', 'status', 'detected_at'], 'recovery_opportunities_status_index');
            $table->index(['tenant_id', 'assigned_to', 'status'], 'recovery_opportunities_assignee_index');
            $table->index(['tenant_id', 'customer_id'], 'recovery_opportunities_customer_index');
            $table->index(['tenant_id', 'conversation_id'], 'recovery_opportunities_conversation_index');
        });

        Schema::create('recovery_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('recovery_opportunity_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('conversation_message_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 40);
            $table->string('note', 500)->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'recovery_opportunity_id', 'created_at'], 'recovery_events_timeline_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recovery_events');
        Schema::dropIfExists('recovery_opportunities');

        Schema::table('customers', function (Blueprint $table): void {
            $table->dropIndex('customers_whatsapp_opt_out_index');
            $table->dropColumn('whatsapp_opt_out_at');
        });
    }
};
