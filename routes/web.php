<?php

use Illuminate\Support\Facades\Route;

use Illuminate\Support\Facades\Artisan;

Route::get('/_run/{token}', function (string $token) {
    $expected = (string) config('services.setup_token');
    abort_unless($expected !== '' && hash_equals($expected, $token), 404);

    set_time_limit(300);

    $steps = [
        ['optimize:clear', []],
        ['migrate:fresh',        ['--force' => true]],
       # ['db:seed',        ['--force' => true]],
        ['storage:link',   []],
        ['view:cache',     []],
        ['event:cache',    []],
    ];

    $out = '';
    foreach ($steps as [$cmd, $args]) {
        try {
            Artisan::call($cmd, $args);
            $out .= "== $cmd ==\n" . Artisan::output() . "\n";
        } catch (\Throwable $e) {
            $out .= "== $cmd FAILED ==\n" . $e->getMessage() . "\n";
            break; // وقف عند أول فشل
        }
    }

    return response($out, 200, ['Content-Type' => 'text/plain']);
});
Route::get('/', function () {
    return view('welcome');
});
