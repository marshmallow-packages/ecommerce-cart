<?php

declare(strict_types=1);

namespace Marshmallow\Ecommerce\Cart\Events;

use Marshmallow\Ecommerce\Cart\Models\ShoppingCart;

final class CartMerged
{
    public function __construct(
        public ShoppingCart $target,
        public ShoppingCart $source,
    ) {}
}
