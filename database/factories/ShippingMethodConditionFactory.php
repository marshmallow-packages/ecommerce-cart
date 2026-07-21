<?php

declare(strict_types=1);

namespace Marshmallow\Ecommerce\Cart\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Marshmallow\Ecommerce\Cart\Models\ShippingMethodCondition;

/**
 * @extends Factory<ShippingMethodCondition>
 */
class ShippingMethodConditionFactory extends Factory
{
    protected $model = ShippingMethodCondition::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'minimum_amount_including_vat' => 0,
            'maximum_amount_including_vat' => null,
        ];
    }
}
