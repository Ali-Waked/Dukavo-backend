<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Identity, location, presentation and 2FA state the account owns.
     *
     * Existing profile columns (name, phone, locale, preferred_currency,
     * avatar_path) are reused; nothing here duplicates them. The TOTP secret and
     * recovery-code hashes are stored as encrypted text because both are
     * credentials, not profile data. `preferences_source` keeps the provenance
     * of detected values so the UI can say "estimated from IP" rather than
     * presenting a guess as verified information.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('bio')->nullable();
            $table->char('country_code', 2)->nullable();
            $table->string('city', 120)->nullable();
            $table->string('timezone', 64)->nullable();
            $table->string('theme', 10)->nullable();
            $table->string('date_format', 20)->nullable();
            $table->string('time_format', 10)->nullable();
            $table->string('phone_country_code', 8)->nullable();
            $table->timestamp('phone_verified_at')->nullable();
            $table->timestamp('password_changed_at')->nullable();
            $table->timestamp('preferences_initialized_at')->nullable();
            $table->json('preferences_source')->nullable();

            // Fortify-compatible two-factor columns. The values are encrypted
            // by the application before they reach these columns.
            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
            $table->timestamp('two_factor_confirmed_at')->nullable();

            $table->index(['country_code']);
            $table->index(['two_factor_confirmed_at']);
        });

        // Accounts that existed before this migration have already completed
        // their initial experience. Marking them prevents a first-login prompt
        // from appearing for every existing user on deploy. New accounts are
        // created with this column NULL and therefore see onboarding once.
        DB::table('users')
            ->whereNull('preferences_initialized_at')
            ->update(['preferences_initialized_at' => DB::raw('created_at')]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['country_code']);
            $table->dropIndex(['two_factor_confirmed_at']);
            $table->dropColumn([
                'bio',
                'country_code',
                'city',
                'timezone',
                'theme',
                'date_format',
                'time_format',
                'phone_country_code',
                'phone_verified_at',
                'password_changed_at',
                'preferences_initialized_at',
                'preferences_source',
                'two_factor_secret',
                'two_factor_recovery_codes',
                'two_factor_confirmed_at',
            ]);
        });
    }
};
