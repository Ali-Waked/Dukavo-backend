<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A user's own display currency.
     *
     * This belongs to the person, not to any workspace: it is used for personal
     * and platform-level financial surfaces, while merchant pages keep using the
     * active store's currency. It deliberately has no foreign key or relationship
     * to `stores`, so changing it can never touch a price or a historical order.
     *
     * The default matches the platform default so existing accounts gain a
     * usable value without a data backfill.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->char('preferred_currency', 3)->default('USD')->after('locale');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('preferred_currency');
        });
    }
};
