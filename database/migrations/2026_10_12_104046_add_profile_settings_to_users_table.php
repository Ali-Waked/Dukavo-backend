<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Profile and preference state a user owns about themselves.
     *
     * `avatar_path` is stored relative to the public disk (the same convention
     * as product images), so the storage driver stays an implementation detail
     * and only the resource builds a URL. `notification_preferences` is a JSON
     * column rather than a table: the set of toggles is small, closed and
     * always read as a whole, so a table would add a join for no benefit.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('avatar_path')->nullable()->after('phone');
            $table->json('notification_preferences')->nullable()->after('last_login_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['avatar_path', 'notification_preferences']);
        });
    }
};
