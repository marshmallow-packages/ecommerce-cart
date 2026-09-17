<?php

declare(strict_types=1);

namespace Marshmallow\Ecommerce\Cart\Events;

use Marshmallow\Ecommerce\Cart\Models\ShoppingCart;

/**
 * A confirmed cart was taken back, e.g. after a failed payment.
 */
final class CartReopened
{
    public function __construct(public ShoppingCart $cart) {}
}
