<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\HelpTag;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<HelpTag>
 */
class HelpTagFactory extends Factory
{
    protected $model = HelpTag::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->word();

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.fake()->unique()->numberBetween(1, 100000),
        ];
    }
}
