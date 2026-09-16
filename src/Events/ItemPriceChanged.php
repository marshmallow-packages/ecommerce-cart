<?php

declare(strict_types=1);

namespace Marshmallow\Ecommerce\Cart\Events;

use Marshmallow\Ecommerce\Cart\Models\ShoppingCart;
use Marshmallow\Ecommerce\Cart\Models\ShoppingCartItem;
use Marshmallow\Ecommerce\Cart\Support\Price;

final class ItemPriceChanged
{
    public function __construct(
        public ShoppingCart $cart,
        public ShoppingCartItem $item,
        public Price $from,
        public Price $to,
    ) {}
}
