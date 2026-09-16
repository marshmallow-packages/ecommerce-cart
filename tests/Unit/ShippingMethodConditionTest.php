<?php

declare(strict_types=1);

use Marshmallow\Ecommerce\Cart\Models\ShippingMethodCondition;

it('matches a subtotal against its band with inclusive bounds', function (int $min, ?int $max, int $subtotal, bool $expected): void {
    $condition = new ShippingMethodCondition([
        'minimum_amount_including_vat' => $min,
        'maximum_amount_including_vat' => $max,
    ]);

    expect($condition->matches($subtotal))->toBe($expected);
})->with([
    'below the minimum' => [1000, 5000, 999, false],
    'exactly the minimum' => [1000, 5000, 1000, true],
    'inside the band' => [1000, 5000, 2500, true],
    'exactly the maximum' => [1000, 5000, 5000, true],
    'above the maximum' => [1000, 5000, 5001, false],
    'open ended above the minimum' => [1000, null, 1000000, true],
    'open ended below the minimum' => [1000, null, 999, false],
    'zero minimum catches an empty cart' => [0, null, 0, true],
]);
