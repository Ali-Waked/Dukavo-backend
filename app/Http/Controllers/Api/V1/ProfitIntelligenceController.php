<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Reporting\ProfitIntelligenceRequest;
use App\Models\Store;
use App\Services\Reporting\ProfitIntelligenceService;
use App\Support\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Profit and margin reporting.
 *
 * The screen and the export share one service so a downloaded file can never
 * disagree with the numbers it sits beside. When the underlying data cannot
 * support a figure, the service returns null and a limitation instead of an
 * invented number.
 */
class ProfitIntelligenceController extends Controller
{
    public function __construct(private readonly ProfitIntelligenceService $profit) {}

    public function overview(ProfitIntelligenceRequest $request): JsonResponse
    {
        [$from, $until] = $request->range();

        return ApiResponse::success('Profit intelligence.', $this->profit->overview(
            $this->store($request),
            $request->currency(),
            CarbonImmutable::parse($from->toDateString()),
            CarbonImmutable::parse($until->toDateString()),
            $request->productLimit(),
        ));
    }

    public function export(ProfitIntelligenceRequest $request): StreamedResponse
    {
        [$from, $until] = $request->range();
        $currency = $request->currency();

        $rows = $this->profit->exportRows(
            $this->store($request),
            $currency,
            CarbonImmutable::parse($from->toDateString()),
            CarbonImmutable::parse($until->toDateString()),
        );

        $filename = 'profit-'.$currency.'-'.$from->toDateString().'-to-'.$until->toDateString().'.csv';

        return response()->streamDownload(function () use ($rows): void {
            $output = fopen('php://output', 'w');

            if ($output === false) {
                return;
            }

            fputcsv($output, [
                'Order',
                'Store',
                'Placed at (workspace time)',
                'Status',
                'Payment status',
                'Currency',
                'Recognized',
                'Gross sales',
                'Discounts',
                'Net sales',
                'Tax collected',
                'Shipping revenue',
                'COGS',
                'Cost complete',
                'Uncosted units',
                'Gross profit',
                'Order total',
            ]);

            foreach ($rows as $row) {
                fputcsv($output, array_map(fn (mixed $value): mixed => $this->csvValue($value), [
                    $row['order_number'],
                    $row['store'],
                    $row['placed_at'],
                    $row['status'],
                    $row['payment_status'],
                    $row['currency'],
                    $row['recognized'],
                    $row['gross_sales'],
                    $row['discounts'],
                    $row['net_sales'],
                    $row['tax_collected'],
                    $row['shipping_revenue'],
                    $row['cogs'],
                    $row['cost_complete'],
                    $row['uncosted_units'],
                    $row['gross_profit'],
                    $row['grand_total'],
                ]));
            }

            fclose($output);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function store(ProfitIntelligenceRequest $request): ?Store
    {
        return $request->filled('store_id')
            ? Store::query()->findOrFail($request->integer('store_id'))
            : null;
    }

    /**
     * Neutralise spreadsheet formulas while leaving numbers intact.
     */
    private function csvValue(mixed $value): mixed
    {
        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_int($value) || is_float($value)) {
            return $value;
        }

        $text = (string) $value;

        if ($text !== '' && in_array($text[0], ['=', '+', '-', '@'], true)) {
            return "'".$text;
        }

        return $text;
    }
}
