<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Complete the conversations table.
     *
     * `closed_at` records when a thread was resolved (the inbox filters on it) and
     * `meta` holds channel-specific payloads the shared schema knows nothing
     * about. Both were read and written by the services before the columns existed,
     * so a thread could be neither created nor closed.
     */
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table): void {
            $table->json('meta')->nullable()->after('subject');
            $table->timestamp('closed_at')->nullable()->after('status');

            $table->index(['tenant_id', 'closed_at'], 'conversations_closed_index');
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table): void {
            $table->dropIndex('conversations_closed_index');
            $table->dropColumn(['meta', 'closed_at']);
        });
    }
};
