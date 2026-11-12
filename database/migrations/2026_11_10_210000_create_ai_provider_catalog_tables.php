<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The AI routing and credential catalog.
     *
     * Providers, their credentials, their models, the task list the routing
     * table is keyed on, and per-provider health. Credentials live in their
     * own table because ownership (platform vs tenant) is a credential
     * property, not a provider property: one provider row, many owners.
     *
     * No prompt text, answer text or API key in cleartext is ever stored in
     * these tables. The credential's key is encrypted by Laravel's encrypter
     * (`AiProviderCredential::$casts`), and only a masked hint is ever read
     * back into a response.
     */
    public function up(): void
    {
        Schema::create('ai_providers', function (Blueprint $table) {
            $table->id();
            $table->string('key', 40)->unique();
            $table->string('name', 80);
            // The adapter that serves this provider, e.g. `xai`, `gemini`,
            // `openai_compatible`. Must be a driver the factory can build.
            $table->string('driver', 40);
            $table->boolean('enabled')->default(true);
            // Lower is earlier. Used to order providers that have no explicit
            // task route (the default chain).
            $table->unsignedInteger('priority')->default(100);
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['enabled', 'priority']);
        });

        Schema::create('ai_provider_credentials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provider_id')->constrained('ai_providers')->cascadeOnDelete();
            // NULL = the platform's own credential. A tenant id makes it a
            // merchant's own (BYOK) credential; isolation is enforced by the
            // resolver, never by trusting the caller.
            $table->foreignId('tenant_id')->nullable()->constrained()->cascadeOnDelete();
            // Encrypted with Laravel's encrypter; never selected into a
            // response. `key_hint` carries the masked last characters only.
            $table->text('api_key');
            $table->string('key_hint', 12)->nullable();
            $table->string('base_url', 255)->nullable();
            $table->json('config')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->string('last_check_status', 40)->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->unique(['provider_id', 'tenant_id']);
            $table->index(['tenant_id', 'provider_id']);
        });

        Schema::create('ai_models', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provider_id')->constrained('ai_providers')->cascadeOnDelete();
            $table->string('key', 120);
            $table->string('display_name', 160)->nullable();
            // list<AiCapability::value>
            $table->json('capabilities');
            $table->boolean('is_active')->default(true);
            $table->boolean('is_default')->default(false);
            // USD per million tokens, mirroring config('ai.pricing') so
            // estimates survive a provider's price change.
            $table->decimal('input_price', 10, 4)->nullable();
            $table->decimal('output_price', 10, 4)->nullable();
            $table->unsignedInteger('context_window')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['provider_id', 'key']);
            $table->index(['provider_id', 'is_active']);
        });

        Schema::create('ai_tasks', function (Blueprint $table) {
            $table->id();
            // AiTask::value; unique because routing is keyed on it.
            $table->string('key', 60)->unique();
            $table->string('label', 120);
            $table->boolean('enabled')->default(true);
            // Task-level caps, overriding the legacy feature configuration.
            $table->unsignedInteger('daily_per_user')->default(0);
            $table->unsignedInteger('daily_per_tenant')->default(0);
            $table->unsignedInteger('monthly_per_tenant')->default(0);
            $table->unsignedInteger('monthly_platform_requests')->default(0);
            $table->unsignedInteger('max_output_tokens')->default(0);
            $table->decimal('temperature', 3, 2)->nullable();
            $table->unsignedInteger('timeout_seconds')->default(0);
            $table->unsignedInteger('max_retries')->default(0);
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('ai_task_routes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained('ai_tasks')->cascadeOnDelete();
            $table->foreignId('provider_id')->constrained('ai_providers')->cascadeOnDelete();
            // NULL = platform routing. A tenant id is a merchant override,
            // only honoured when BYOK is enabled for that tenant.
            $table->foreignId('tenant_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('model', 120)->nullable();
            // Lower is earlier within the task; the first route is primary,
            // the rest are fallbacks in listed order.
            $table->unsignedInteger('priority')->default(100);
            $table->boolean('is_fallback')->default(false);
            $table->unsignedInteger('max_output_tokens')->nullable();
            $table->decimal('temperature', 3, 2)->nullable();
            $table->unsignedInteger('timeout_seconds')->default(0);
            $table->unsignedInteger('max_retries')->default(0);
            $table->timestamps();

            $table->unique(['task_id', 'provider_id', 'tenant_id']);
            $table->index(['task_id', 'priority']);
            $table->index(['tenant_id', 'task_id']);
        });

        Schema::create('ai_provider_health', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provider_id')->unique()->constrained('ai_providers')->cascadeOnDelete();
            $table->string('state', 20)->default('healthy');
            $table->unsignedInteger('consecutive_failures')->default(0);
            $table->unsignedInteger('failure_count')->default(0);
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('cooldown_until')->nullable();
            $table->string('last_error_code', 60)->nullable();
            $table->timestamp('last_error_at')->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->unsignedInteger('last_latency_ms')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->string('last_check_status', 40)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_provider_health');
        Schema::dropIfExists('ai_task_routes');
        Schema::dropIfExists('ai_tasks');
        Schema::dropIfExists('ai_models');
        Schema::dropIfExists('ai_provider_credentials');
        Schema::dropIfExists('ai_providers');
    }
};
