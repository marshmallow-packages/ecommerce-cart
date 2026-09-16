<?php

declare(strict_types=1);

namespace Marshmallow\Ecommerce\Cart\Events;

use Marshmallow\Ecommerce\Cart\Models\ShippingMethod;
use Marshmallow\Ecommerce\Cart\Models\ShoppingCart;
use Marshmallow\Ecommerce\Cart\Support\Price;

final class ShippingCalculated
{
    public function __construct(
        public ShoppingCart $cart,
        public ?ShippingMethod $method,
        public Price $cost,
    ) {}
}
