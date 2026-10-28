<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Orders raised from an inbox conversation keep a link back to the thread.
     * Nullable because orders can still be placed directly through the API or
     * imported, with no conversation behind them.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->foreignId('conversation_id')->nullable()->after('customer_id')
                ->constrained()->nullOnDelete();
            $table->index(['tenant_id', 'conversation_id'], 'orders_conversation_index');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropIndex('orders_conversation_index');
            $table->dropConstrainedForeignId('conversation_id');
        });
    }
};
