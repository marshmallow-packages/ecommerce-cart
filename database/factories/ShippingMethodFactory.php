<?php

declare(strict_types=1);

namespace Marshmallow\Ecommerce\Cart\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Marshmallow\Ecommerce\Cart\Models\ShippingMethod;

/**
 * @extends Factory<ShippingMethod>
 */
class ShippingMethodFactory extends Factory
{
    protected $model = ShippingMethod::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'PostNL',
            'type' => 'standard',
            'price_including_vat' => 495,
            'vat_percentage' => 21.0,
            'currency' => 'EUR',
            'sort' => 0,
        ];
    }

    public function free(): static
    {
        return $this->state(['price_including_vat' => 0]);
    }

    public function expired(): static
    {
        return $this->state([
            'valid_from' => now()->subYear(),
            'valid_till' => now()->subDay(),
        ]);
    }
}
