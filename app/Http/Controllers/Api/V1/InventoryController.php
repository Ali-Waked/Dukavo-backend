<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\InventoryMovementType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\AdjustStockRequest;
use App\Http\Resources\InventoryMovementResource;
use App\Http\Resources\InventoryResource;
use App\Models\Inventory;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Services\Inventory\InventoryService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Stock levels and their movement ledger.
 *
 * Two different intentions live here and they are deliberately separate
 * endpoints: `adjust` states a new absolute quantity (a stock take), while
 * `receive` adds stock that arrived from a supplier. Both go through the ledger,
 * so the history explains every number on screen.
 */
class InventoryController extends Controller
{
    public function __construct(private readonly InventoryService $inventory) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $storeId = $this->storeId($request);

        $inventories = Inventory::query()
            ->with(['product', 'variant', 'store'])
            ->when($storeId, fn ($query) => $query->where('store_id', $storeId))
            ->when($request->filled('product_variant_id'), fn ($query) => $query->where('product_variant_id', $request->integer('product_variant_id')))
            ->when($request->boolean('low_stock'), fn ($query) => $query->lowStock())
            ->orderBy('quantity')
            ->orderBy('id')
            ->paginate(min($request->integer('per_page', 20), 100))
            ->withQueryString();

        return InventoryResource::collection($inventories);
    }

    /**
     * Rows that have fallen to their low-stock threshold, for the dashboard
     * widget and the reorder list.
     */
    public function lowStock(Request $request): AnonymousResourceCollection
    {
        $storeId = $this->storeId($request);

        return InventoryResource::collection(
            $this->inventory->lowStock(
                $storeId === null ? null : Store::query()->findOrFail($storeId),
                min($request->integer('limit', 10), 50),
            ),
        );
    }

    /**
     * The movement ledger for one inventory row.
     */
    public function movements(Request $request, Inventory $inventory): AnonymousResourceCollection
    {
        return InventoryMovementResource::collection(
            $inventory->movements()
                ->with('user')
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->paginate(min($request->integer('per_page', 20), 100))
                ->withQueryString()
        );
    }

    /**
     * Stock take: set the on-hand quantity to a counted value.
     */
    public function adjust(AdjustStockRequest $request): JsonResponse
    {
        $inventory = $this->resolveInventory(
            $request->integer('store_id'),
            $request->integer('product_variant_id'),
        );

        $movement = $this->inventory->adjustToQuantity(
            $inventory,
            $request->integer('quantity'),
            $request->validated('note'),
        );

        return ApiResponse::created('Stock adjusted.', new InventoryMovementResource($movement));
    }

    /**
     * Receive stock from a supplier.
     */
    public function receive(AdjustStockRequest $request): JsonResponse
    {
        $inventory = $this->resolveInventory(
            $request->integer('store_id'),
            $request->integer('product_variant_id'),
            $request->validated('low_stock_threshold'),
        );

        $movement = $this->inventory->applyMovement(
            $inventory,
            InventoryMovementType::Purchase,
            $request->integer('quantity'),
            $request->validated('note'),
        );

        return ApiResponse::created('Stock received.', new InventoryMovementResource($movement));
    }

    /**
     * Create (or fetch) the inventory row for a store/variant pair. Both lookups
     * are tenant scoped, so a foreign store or variant resolves to a 404 rather
     * than to somebody else's stock.
     */
    private function resolveInventory(int $storeId, int $variantId, ?int $lowStockThreshold = null): Inventory
    {
        $store = Store::query()->findOrFail($storeId);
        $variant = ProductVariant::query()->with('product')->findOrFail($variantId);

        return $this->inventory->findOrCreate($store, $variant, $lowStockThreshold);
    }

    private function storeId(Request $request): ?int
    {
        return $request->filled('store_id') ? $request->integer('store_id') : null;
    }
}
