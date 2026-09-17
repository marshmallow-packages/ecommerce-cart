<?php

declare(strict_types=1);

namespace Marshmallow\Ecommerce\Cart\Events;

use Marshmallow\Ecommerce\Cart\Models\ShippingMethod;
use Marshmallow\Ecommerce\Cart\Models\ShoppingCart;
use Marshmallow\Ecommerce\Cart\Support\Price;

/**
 * The customer picked a shipping method (null: no shipping, e.g. pickup).
 */
final class ShippingMethodSelected
{
    public function __construct(
        public ShoppingCart $cart,
        public ?ShippingMethod $method,
        public Price $cost,
    ) {}
}
