<?php

declare(strict_types=1);

namespace App\Services\Automation;

use App\Enums\AutomationRunLogLevel;
use App\Models\AutomationRun;
use App\Models\AutomationRunLog;
use Illuminate\Support\Str;
use Throwable;

/**
 * Writes the structured trace attached to one automation run.
 *
 * Logging must never take down the execution path: a failure to record a line is
 * reported and swallowed, because the side effect has already happened (or not)
 * and a logging error must not cause a queue retry that duplicates it.
 */
final class AutomationRunLogger
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function log(
        AutomationRun $run,
        AutomationRunLogLevel $level,
        string $message,
        array $context = [],
    ): void {
        try {
            AutomationRunLog::query()->create([
                'tenant_id' => $run->tenant_id,
                'automation_run_id' => $run->getKey(),
                'level' => $level,
                'message' => Str::limit($message, 500, ''),
                'context' => $context === [] ? null : $context,
                'created_at' => now(),
            ]);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function info(AutomationRun $run, string $message, array $context = []): void
    {
        $this->log($run, AutomationRunLogLevel::Info, $message, $context);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function warning(AutomationRun $run, string $message, array $context = []): void
    {
        $this->log($run, AutomationRunLogLevel::Warning, $message, $context);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function error(AutomationRun $run, string $message, array $context = []): void
    {
        $this->log($run, AutomationRunLogLevel::Error, $message, $context);
    }
}
