<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Signups for the Dukavo waitlist.
     *
     * Platform-level (no `tenant_id`): the waitlist belongs to Dukavo itself,
     * never to a merchant. The email is unique at the database so a concurrent
     * double submit cannot produce two rows, and the default status keeps a
     * raw signup in `pending` without application code having to remember it.
     */
    public function up(): void
    {
        Schema::create('waitlist_entries', function (Blueprint $table) {
            $table->id();
            $table->string('email', 190)->unique();
            $table->string('name', 120)->nullable();
            $table->string('business_type', 80)->nullable();
            $table->string('source', 80)->nullable();
            $table->string('status', 20)->default('pending');
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('waitlist_entries');
    }
};
