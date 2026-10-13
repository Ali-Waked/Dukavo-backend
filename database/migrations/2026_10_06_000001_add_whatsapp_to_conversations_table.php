<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table): void {
            $table->foreignId('whatsapp_account_id')->nullable()->after('store_id')->constrained()->nullOnDelete();
            $table->string('display_name')->nullable()->after('external_id');
            $table->string('phone')->nullable()->after('display_name');
            $table->index(['whatsapp_account_id']);
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table): void {
            $table->dropIndex(['whatsapp_account_id']);
            $table->dropConstrainedForeignId('whatsapp_account_id');
            $table->dropColumn(['display_name', 'phone']);
        });
    }
};
