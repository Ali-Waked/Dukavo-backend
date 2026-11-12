<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A channel-scoped identity for a customer.
     *
     * One person may reach a merchant through several channels. This table
     * records each verified provider identity separately and links it to a
     * single customer. Identities are never merged automatically on a matching
     * name or username; linking is an explicit, auditable action.
     */
    public function up(): void
    {
        Schema::create('customer_identities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('channel', 40);
            $table->string('external_id', 191);
            $table->string('username')->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('email')->nullable();
            $table->string('display_name')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('consent_at')->nullable();
            $table->timestamp('opted_out_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'channel', 'external_id'], 'customer_identities_unique');
            $table->index(['tenant_id', 'customer_id'], 'customer_identities_customer_index');
            $table->index(['tenant_id', 'phone'], 'customer_identities_phone_index');
            $table->index(['tenant_id', 'email'], 'customer_identities_email_index');
            $table->index(['tenant_id', 'channel', 'opted_out_at'], 'customer_identities_optout_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_identities');
    }
};
