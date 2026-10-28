<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_actions', function (Blueprint $table) {
            $table->string('classification', 30)->nullable()->after('action_type');
            $table->foreignId('confirmed_by')->nullable()->after('approved_by')->constrained('users')->nullOnDelete();
            $table->foreignId('executed_by')->nullable()->after('confirmed_by')->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable()->after('approved_at');
            $table->timestamp('expires_at')->nullable()->after('executed_at');

            // Idempotency is a tenant contract, not a global one: the same key
            // used by two merchants must create two separate proposals.
            $table->dropUnique('ai_actions_idempotency_key_unique');
            $table->unique(['tenant_id', 'idempotency_key'], 'ai_actions_tenant_idempotency_key_unique');
            $table->index(['status', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::table('ai_actions', function (Blueprint $table) {
            $table->dropIndex(['status', 'expires_at']);
            $table->dropUnique('ai_actions_tenant_idempotency_key_unique');
            $table->unique('idempotency_key', 'ai_actions_idempotency_key_unique');

            $table->dropConstrainedForeignId('executed_by');
            $table->dropConstrainedForeignId('confirmed_by');
            $table->dropColumn(['classification', 'confirmed_at', 'expires_at']);
        });
    }
};
