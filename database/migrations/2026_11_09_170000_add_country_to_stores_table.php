<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The merchant country is distinct from the customer's address country.
     *
     * Payment eligibility is decided by where the store is legally established
     * (its provider onboarding country), never by where a card was issued. The
     * column stays nullable so existing stores are not silently assigned a
     * country; until a merchant sets one, online payment is correctly reported
     * as unavailable.
     */
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table): void {
            $table->char('country', 2)->nullable()->after('phone');
            $table->index(['tenant_id', 'country']);
        });
    }

    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table): void {
            $table->dropIndex(['tenant_id', 'country']);
            $table->dropColumn('country');
        });
    }
};
