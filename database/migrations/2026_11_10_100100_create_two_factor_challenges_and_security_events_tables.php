<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Short-lived second-factor challenges and the account security history.
     *
     * A challenge row is the only thing a caller receives between a correct
     * password and a correct TOTP/recovery code. The browser holds the random
     * token; the database stores only its SHA-256 hash, so a leaked table cannot
     * be used to sign in. `consumed_at` makes the challenge single-use and
     * `attempts` allows per-challenge brute-force protection on top of HTTP rate
     * limiting.
     *
     * Security events are intentionally a separate, append-only table from
     * `audit_logs`: the account holder needs a readable history, while the audit
     * trail serves operators. Neither table accepts passwords, tokens, TOTP
     * secrets or recovery codes; the logger filters those keys and callers never
     * pass them.
     */
    public function up(): void
    {
        Schema::create('two_factor_challenges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->timestamp('expires_at')->index();
            $table->timestamp('consumed_at')->nullable()->index();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });

        Schema::create('security_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('event', 64);
            $table->string('status', 10)->default('success');
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->string('device', 120)->nullable();
            $table->char('country_code', 2)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->nullable()->index();

            $table->index(['user_id', 'created_at']);
            $table->index(['event', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('security_events');
        Schema::dropIfExists('two_factor_challenges');
    }
};
