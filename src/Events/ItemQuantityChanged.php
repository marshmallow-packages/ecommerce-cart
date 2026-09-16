<?php

declare(strict_types=1);

namespace Marshmallow\Ecommerce\Cart\Events;

use Marshmallow\Ecommerce\Cart\Models\ShoppingCart;
use Marshmallow\Ecommerce\Cart\Models\ShoppingCartItem;

final class ItemQuantityChanged
{
    public function __construct(
        public ShoppingCart $cart,
        public ShoppingCartItem $item,
        public int $from,
        public int $to,
    ) {}
}
