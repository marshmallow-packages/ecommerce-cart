<?php

declare(strict_types=1);

namespace Marshmallow\Ecommerce\Cart\Events;

use Marshmallow\Ecommerce\Cart\Models\ShoppingCart;

final class CartAbandoned
{
    public function __construct(public ShoppingCart $cart) {}
}
