<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A payment provider the platform knows about.
     *
     * `status` says whether the provider may be offered at all;
     * `integration_enabled` is a separate switch because cataloguing a
     * provider's documented coverage is not the same as having an adapter that
     * can move money. Both must be true before a merchant can connect.
     *
     * No credential is ever stored on this row: platform operators manage the
     * catalogue, while merchant secrets live encrypted on a store connection.
     */
    public function up(): void
    {
        Schema::create('payment_providers', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('name');
            $table->string('status', 20)->default('pending');
            $table->boolean('integration_enabled')->default(false);
            $table->string('adapter')->nullable();
            $table->string('docs_url')->nullable();
            $table->string('webhook_docs_url')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('last_verified_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'integration_enabled']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_providers');
    }
};
