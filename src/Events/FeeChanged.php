<?php

declare(strict_types=1);

namespace Marshmallow\Ecommerce\Cart\Events;

use Marshmallow\Ecommerce\Cart\Models\ShoppingCart;
use Marshmallow\Ecommerce\Cart\Support\Price;

/**
 * The fee line was set or cleared (null: no fee any more).
 */
final class FeeChanged
{
    public function __construct(
        public ShoppingCart $cart,
        public string $description,
        public ?Price $fee,
    ) {}
}
