<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Centralised platform configuration.
     *
     * `platform_settings` stores only values an operator deliberately
     * overrides. A missing row is meaningful: the resolver falls back to the
     * deployment environment and then the definition default, which is why an
     * untouched installation behaves exactly as it did before this table
     * existed.
     *
     * Secrets are encrypted by the application before they reach `value`;
     * the column never holds plaintext. `secret_hint` keeps only the last four
     * characters so the dashboard can say "configured ••••abcd" without ever
     * reading the credential back.
     *
     * Infrastructure secrets (APP_KEY, database and queue credentials) are
     * deliberately *not* representable here — they remain environment-managed
     * and are exposed read-only through the configuration registry.
     */
    public function up(): void
    {
        Schema::create('platform_settings', function (Blueprint $table) {
            $table->id();
            $table->string('category', 40);
            $table->string('key', 120)->unique();
            $table->text('value')->nullable();
            $table->boolean('is_secret')->default(false);
            $table->string('secret_hint', 8)->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['category', 'key']);
        });

        /*
         * The outcome of the last explicit or scheduled probe of one
         * integration. A row is evidence of a real check, never a guess:
         * absence means "not tested", and `checked_at` is always set.
         */
        Schema::create('integration_health_checks', function (Blueprint $table) {
            $table->id();
            $table->string('integration', 60)->unique();
            $table->string('state', 40);
            $table->string('summary', 500)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('checked_at');
            $table->timestamp('failed_at')->nullable();
            $table->foreignId('checked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_health_checks');
        Schema::dropIfExists('platform_settings');
    }
};
