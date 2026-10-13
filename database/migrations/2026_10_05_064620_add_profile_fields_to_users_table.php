<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Authentication, RBAC and tenancy need a small amount of profile state.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('phone', 32)->nullable()->after('email');
            $table->string('status', 20)->default('active')->after('password');
            $table->string('locale', 10)->default('en')->after('status');
            $table->timestamp('last_login_at')->nullable()->after('locale');

            $table->index(['status']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['status']);
            $table->dropColumn(['phone', 'status', 'locale', 'last_login_at']);
        });
    }
};
