<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Widen monetary columns to four decimal places.
     *
     * Currencies such as the Kuwaiti dinar use three minor units, and a
     * converted price must not be rounded twice (once on write, again on
     * display). Widening is non-destructive: existing values keep their exact
     * amount and simply gain trailing zeroes.
     *
     * Historical order columns are widened too, but their values are never
     * rewritten by a currency change — precision is a storage concern, not a
     * conversion policy.
     *
     * Each entry restates the column's default and nullability: `change()`
     * replaces the whole definition, so omitting them would silently drop a
     * default the application relies on.
     *
     * @var array<string, array<string, array{default: int|float|null, nullable: bool}>>
     */
    private const COLUMNS = [
        'products' => [
            'price' => ['default' => 0, 'nullable' => false],
            'cost' => ['default' => 0, 'nullable' => false],
        ],
        'product_variants' => [
            'price' => ['default' => null, 'nullable' => false],
            'cost' => ['default' => 0, 'nullable' => false],
        ],
        'orders' => [
            'subtotal' => ['default' => 0, 'nullable' => false],
            'discount_total' => ['default' => 0, 'nullable' => false],
            'tax_total' => ['default' => 0, 'nullable' => false],
            'shipping_total' => ['default' => 0, 'nullable' => false],
            'grand_total' => ['default' => 0, 'nullable' => false],
        ],
        'order_items' => [
            'unit_price' => ['default' => null, 'nullable' => false],
            'unit_cost' => ['default' => null, 'nullable' => true],
            'discount_total' => ['default' => 0, 'nullable' => false],
            'tax_total' => ['default' => 0, 'nullable' => false],
            'line_total' => ['default' => 0, 'nullable' => false],
        ],
        'carts' => [
            'subtotal' => ['default' => 0, 'nullable' => false],
            'discount_total' => ['default' => 0, 'nullable' => false],
            'tax_total' => ['default' => 0, 'nullable' => false],
            'shipping_total' => ['default' => 0, 'nullable' => false],
            'grand_total' => ['default' => 0, 'nullable' => false],
        ],
        'cart_items' => [
            'unit_price' => ['default' => null, 'nullable' => false],
            'discount_total' => ['default' => 0, 'nullable' => false],
            'line_total' => ['default' => null, 'nullable' => false],
        ],
        'bundles' => [
            'discount_value' => ['default' => 0, 'nullable' => false],
        ],
        'recovery_opportunities' => [
            'estimated_value' => ['default' => null, 'nullable' => true],
        ],
    ];

    public function up(): void
    {
        $this->resize(18, 4);
    }

    public function down(): void
    {
        $this->resize(12, 2);
    }

    private function resize(int $precision, int $scale): void
    {
        foreach (self::COLUMNS as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $existing = array_filter(
                array_keys($columns),
                fn (string $column): bool => Schema::hasColumn($table, $column),
            );

            if ($existing === []) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($columns, $existing, $precision, $scale): void {
                foreach ($existing as $column) {
                    $definition = $columns[$column];

                    $change = $blueprint->decimal($column, $precision, $scale);

                    if ($definition['nullable']) {
                        $change->nullable();
                    }

                    if ($definition['default'] !== null) {
                        $change->default($definition['default']);
                    }

                    $change->change();
                }
            });
        }
    }
};
