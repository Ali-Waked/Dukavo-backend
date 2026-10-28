<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Make every ledger entry attributable and safely retryable.
     *
     * `source` names the workflow that caused the movement, while
     * `idempotency_key` lets a client or stock transfer retry without posting
     * the same physical change twice.
     */
    public function up(): void
    {
        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->string('source', 40)->nullable()->after('note');
            $table->string('idempotency_key', 100)->nullable()->after('source');

            $table->unique(['tenant_id', 'idempotency_key']);
            $table->index(['tenant_id', 'source']);
        });
    }

    public function down(): void
    {
        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'idempotency_key']);
            $table->dropIndex(['tenant_id', 'source']);
            $table->dropColumn(['source', 'idempotency_key']);
        });
    }
};
