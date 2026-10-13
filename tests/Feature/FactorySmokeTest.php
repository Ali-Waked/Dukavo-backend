<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FactorySmokeTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_factory_can_build_a_record(): void
    {
        $models = [Models\Tenant::class, Models\User::class, Models\Store::class, Models\Category::class, Models\Product::class, Models\ProductVariant::class, Models\Inventory::class, Models\Customer::class, Models\Order::class, Models\Conversation::class];

        foreach ($models as $model) {
            $record = $model::factory()->create();
            $this->assertNotNull($record->getKey(), $model.' factory produced no key');
        }
    }
}
