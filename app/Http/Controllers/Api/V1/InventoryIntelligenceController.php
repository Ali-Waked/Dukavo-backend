<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\InventoryIntelligenceRequest;
use App\Models\Inventory;
use App\Models\Store;
use App\Services\Inventory\InventoryIntelligenceService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Explainable stock health, demand and replenishment.
 *
 * The endpoints answer three questions with the same analysis: what needs
 * attention now, why is this row behaving this way, and what does the whole
 * filtered set look like as a file. Current stock is live; the date range only
 * controls the history used for demand and stockout metrics.
 */
class InventoryIntelligenceController extends Controller
{
    public function __construct(private readonly InventoryIntelligenceService $intelligence) {}

    public function overview(InventoryIntelligenceRequest $request): JsonResponse
    {
        [$from, $until] = $request->range();

        return ApiResponse::success('Inventory intelligence.', $this->intelligence->overview(
            $this->store($request),
            $from,
            $until,
            $request->statusFilter(),
            $request->searchTerm(),
            $request->integer('page', 1),
            min(max(1, $request->integer('per_page', 20)), 100),
        ));
    }

    public function show(InventoryIntelligenceRequest $request, Inventory $inventory): JsonResponse
    {
        [$from, $until] = $request->range();

        return ApiResponse::success('Inventory insight.', $this->intelligence->detail($inventory, $from, $until));
    }

    public function export(InventoryIntelligenceRequest $request): StreamedResponse
    {
        [$from, $until] = $request->range();

        $rows = $this->intelligence->exportRows(
            $this->store($request),
            $from,
            $until,
            $request->statusFilter(),
            $request->searchTerm(),
        );

        $filename = 'inventory-intelligence-'.$from->toDateString().'-to-'.$until->toDateString().'.csv';

        return response()->streamDownload(function () use ($rows): void {
            $output = fopen('php://output', 'w');

            if ($output === false) {
                return;
            }

            fputcsv($output, [
                'Store',
                'Product',
                'Variant',
                'SKU',
                'On hand',
                'Reserved',
                'Available',
                'Reorder point',
                'Status',
                'Units sold (range)',
                'Daily demand',
                'Forecast confidence',
                'Forecast status',
                'Suggested order',
                'Unit cost',
                'Inventory value',
                'Currency',
                'Last sale',
                'Last stockout',
            ]);

            foreach ($rows as $row) {
                fputcsv($output, array_map(fn (mixed $value): mixed => $this->csvValue($value), [
                    $row['store'],
                    $row['product_name'],
                    $row['variant_name'],
                    $row['sku'],
                    $row['quantity'],
                    $row['reserved_quantity'],
                    $row['available'],
                    $row['replenishment']['reorder_point'],
                    $row['status'],
                    $row['units_sold'],
                    $row['forecast']['daily_demand'],
                    $row['forecast']['confidence'],
                    $row['forecast']['status'],
                    $row['replenishment']['suggested_quantity'],
                    $row['unit_cost'],
                    $row['inventory_value'],
                    $row['currency'],
                    $row['last_sale_at'],
                    $row['last_stockout_at'],
                ]));
            }

            fclose($output);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function store(InventoryIntelligenceRequest $request): ?Store
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
