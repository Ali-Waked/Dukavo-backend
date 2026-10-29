<?php

declare(strict_types=1);

namespace App\Support;

use App\Services\Analytics\AnalyticsMetricCatalog;
use Illuminate\Support\Facades\File;

class AnalyticsDocs
{
    public static function path(): string
    {
        return base_path('docs/analytics-metric-definitions.md');
    }

    public static function generate(): void
    {
        $defs = AnalyticsMetricCatalog::definitions();
        $md = "# Analytics Metric Definitions\n\n";
        $md .= "All metrics are tenant-isolated and respect store/date/timezone filters where applicable.\n\n";
        $md .= "Revenue is not profit. Financial margin is only reported when reliable cost snapshots exist.\n\n";

        foreach ($defs as $key => $def) {
            $md .= "## {$def['name']} (`{$key}`)\n\n";
            $md .= "- Description: {$def['description']}\n";
            $md .= "- Unit: {$def['unit']}\n";
            $md .= "- Source: {$def['source']}\n";
            $md .= '- Filters: '.implode(', ', $def['filters'])."\n";
            if (isset($def['notes'])) {
                $md .= "- Notes: {$def['notes']}\n";
            }
            $md .= "\n";
        }

        File::ensureDirectoryExists(dirname(self::path()));
        File::put(self::path(), $md);
    }
}
