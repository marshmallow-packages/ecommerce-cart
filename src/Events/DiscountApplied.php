<?php

declare(strict_types=1);

namespace Marshmallow\Ecommerce\Cart\Events;

use Illuminate\Support\Collection;
use Marshmallow\Ecommerce\Cart\Models\Discount;
use Marshmallow\Ecommerce\Cart\Models\ShoppingCart;
use Marshmallow\Ecommerce\Cart\Support\Price;

/**
 * A voucher landed on the cart. `amount` is the gross total taken off (a
 * negative number of cents); `prices` holds the negative line per VAT rate.
 */
final class DiscountApplied
{
    /**
     * @param  Collection<int, Price>  $prices
     */
    public function __construct(
        public ShoppingCart $cart,
        public Discount $discount,
        public int $amount,
        public Collection $prices,
    ) {}
}
