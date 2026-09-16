<?php

declare(strict_types=1);

namespace Marshmallow\Ecommerce\Cart\Events;

use Marshmallow\Ecommerce\Cart\Models\Discount;
use Marshmallow\Ecommerce\Cart\Models\ShoppingCart;

final class DiscountRejected
{
    public function __construct(
        public ShoppingCart $cart,
        public ?Discount $discount,
        public string $reason,
    ) {}
}
