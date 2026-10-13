<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * People who explicitly consented to receive Dukavo newsletters.
     *
     * Platform-level (no `tenant_id`): a newsletter goes to people interested
     * in Dukavo itself, never to a merchant's customers. Consent is a first
     * class fact — `consented_at` is written when the address subscribes and
     * the act of subscribing is the consent; there is no email provider yet,
     * so nothing is assumed verified. `email` is unique at the database so a
     * concurrent double submit cannot create two rows, and the default status
     * keeps a fresh signup `subscribed` without application code remembering
     * it.
     */
    public function up(): void
    {
        Schema::create('newsletter_subscribers', function (Blueprint $table) {
            $table->id();
            $table->string('email', 190)->unique();
            $table->string('status', 20)->default('subscribed');
            $table->string('source', 80)->nullable();
            $table->timestamp('consented_at')->nullable();
            $table->timestamp('unsubscribed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('newsletter_subscribers');
    }
};
