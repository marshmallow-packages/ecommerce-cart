<?php

declare(strict_types=1);

namespace Marshmallow\Ecommerce\Cart\Events;

use Marshmallow\Ecommerce\Cart\Models\ShoppingCart;
use Marshmallow\Ecommerce\Cart\Models\ShoppingCartItem;

final class ItemRemoved
{
    public function __construct(
        public ShoppingCart $cart,
        public ShoppingCartItem $item,
    ) {}
}
