<?php

declare(strict_types=1);

namespace Marshmallow\Ecommerce\Cart\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Marshmallow\Ecommerce\Cart\Models\Prospect;

/**
 * @extends Factory<Prospect>
 */
class ProspectFactory extends Factory
{
    protected $model = Prospect::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'first_name' => $this->faker->firstName(),
            'last_name' => $this->faker->lastName(),
            'email' => $this->faker->unique()->safeEmail(),
            'phone_number' => $this->faker->phoneNumber(),
        ];
    }
}
