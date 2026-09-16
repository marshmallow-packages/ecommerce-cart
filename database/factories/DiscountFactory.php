<?php

declare(strict_types=1);

namespace Marshmallow\Ecommerce\Cart\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Marshmallow\Ecommerce\Cart\Enums\DiscountAppliesTo;
use Marshmallow\Ecommerce\Cart\Enums\DiscountEligibility;
use Marshmallow\Ecommerce\Cart\Enums\DiscountPrerequisite;
use Marshmallow\Ecommerce\Cart\Enums\DiscountType;
use Marshmallow\Ecommerce\Cart\Models\Discount;

/**
 * @extends Factory<Discount>
 */
class DiscountFactory extends Factory
{
    protected $model = Discount::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'discount_code' => strtoupper($this->faker->unique()->bothify('SAVE####')),
            'discount_type' => DiscountType::FixedAmount,
            'applies_to' => DiscountAppliesTo::All,
            'prerequisite_type' => DiscountPrerequisite::None,
            'eligible_for' => DiscountEligibility::All,
            'is_active' => true,
            'is_once_per_customer' => false,
            'fixed_amount' => 500,
        ];
    }

    public function percentage(float $percent): static
    {
        return $this->state([
            'discount_type' => DiscountType::Percentage,
            'percentage_amount' => $percent,
            'fixed_amount' => null,
        ]);
    }

    public function freeShipping(): static
    {
        return $this->state([
            'discount_type' => DiscountType::FreeShipping,
            'fixed_amount' => null,
        ]);
    }
}
