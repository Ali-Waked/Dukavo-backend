<?php

use Illuminate\Support\Facades\Route;

use Illuminate\Support\Facades\Artisan;


Route::post('/_run', function (\Illuminate\Http\Request $request) {
    $expected = (string) config('services.setup_token');
    $given = (string) $request->header('X-Setup-Token');
    abort_unless($expected !== '' && hash_equals($expected, $given), 404);

    set_time_limit(300);

    $steps = [
        ['optimize:clear', []],
        ['migrate',        ['--force' => true]],
        ['storage:link',   []],
        ['config:cache',   []],
        ['route:cache',    []],
        ['view:cache',     []],
        ['event:cache',    []],
    ];

    // الـ seed بس لو طلبته صراحة: ?seed=1
    if ($request->boolean('seed')) {
        array_splice($steps, 2, 0, [['db:seed', ['--force' => true]]]);
    }

    $out = '';
    foreach ($steps as [$cmd, $args]) {
        try {
            Artisan::call($cmd, $args);
            $out .= "== $cmd ==\n" . Artisan::output() . "\n";
        } catch (\Throwable $e) {
            $out .= "== $cmd FAILED ==\n" . $e->getMessage() . "\n";
            break;
        }
    }

    return response($out, 200, ['Content-Type' => 'text/plain']);
});
