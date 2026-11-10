<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The platform's country catalogue.
     *
     * It is data, not a PHP enum, because a platform administrator must be able
     * to enable or disable a market without a deployment. The ISO code is the
     * primary business key: provider coverage rows reference it directly.
     */
    public function up(): void
    {
        Schema::create('payment_countries', function (Blueprint $table): void {
            $table->id();
            $table->char('code', 2)->unique();
            $table->string('name');
            $table->string('name_ar');
            $table->char('currency', 3);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['is_active', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_countries');
    }
};
