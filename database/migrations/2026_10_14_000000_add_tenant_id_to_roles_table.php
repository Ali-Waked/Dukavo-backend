<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Give tenant scoped roles an owning tenant.
 *
 * Until now every role lived in one global table, so a custom role minted by
 * one merchant was visible, editable and assignable by every other merchant.
 * System tenant roles (`merchant`, `staff`) stay global templates with a NULL
 * `tenant_id`; platform roles are NULL as well; only roles a merchant creates
 * themselves carry their tenant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->foreignId('tenant_id')
                ->nullable()
                ->after('id')
                ->constrained('tenants')
                ->nullOnDelete();

            $table->index(['tenant_id', 'scope']);
        });
    }

    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'scope']);
            $table->dropConstrainedForeignId('tenant_id');
        });
    }
};
