<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

Route::get('/_run/{token}', function (string $token) {
    $expected = (string) config('services.setup_token');
    abort_unless($expected !== '' && hash_equals($expected, $token), 404);

    set_time_limit(300);
    $out = '';

    try {
        // 1) الـ migrations الجديدة فقط
        Artisan::call('migrate', ['--force' => true]);
        $out .= "== migrate ==\n" . Artisan::output() . "\n";

        // 2) جدول تتبع الـ seeders
        if (! Schema::hasTable('seeder_runs')) {
            Schema::create('seeder_runs', function ($table) {
                $table->id();
                $table->string('seeder')->unique();
                $table->timestamp('ran_at')->useCurrent();
            });
        }

        // 3) DatabaseSeeder كامل، مرة وحدة
        $key = 'DatabaseSeeder';
        if (! DB::table('seeder_runs')->where('seeder', $key)->exists()) {
            Artisan::call('db:seed', ['--force' => true]);
            DB::table('seeder_runs')->insert(['seeder' => $key]);
            $out .= "== db:seed ==\n" . Artisan::output() . "\n";
        } else {
            $out .= "== db:seed skipped (already ran) ==\n\n";
        }

        // 4) باقي الخطوات
        foreach (['storage:link', 'view:cache', 'event:cache'] as $cmd) {
            try {
                Artisan::call($cmd);
                $out .= "== $cmd ==\n" . Artisan::output() . "\n";
            } catch (\Throwable $e) {
                $out .= "== $cmd skipped: " . $e->getMessage() . "\n";
            }
        }
    } catch (\Throwable $e) {
        return response($out . "== FAILED ==\n" . $e->getMessage(), 500, ['Content-Type' => 'text/plain']);
    }

    return response($out, 200, ['Content-Type' => 'text/plain']);
});

Route::get('/', function () {
    return view('welcome');
});
