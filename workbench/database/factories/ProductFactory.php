<?php

declare(strict_types=1);

namespace Workbench\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Workbench\App\Models\Product;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => $this->faker->words(2, true),
            'price_cents' => $this->faker->numberBetween(500, 50000),
            'vat_percentage' => 21.0,
            'stock' => 100,
            'category_ids' => [],
        ];
    }

    public function outOfStock(): static
    {
        return $this->state(['stock' => 0]);
    }
}
