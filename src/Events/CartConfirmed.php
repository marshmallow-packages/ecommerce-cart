<?php

declare(strict_types=1);

namespace Marshmallow\Ecommerce\Cart\Events;

use Marshmallow\Ecommerce\Cart\Models\ShoppingCart;

/**
 * A cart passed every pre-payment check and is now frozen for payment.
 */
final class CartConfirmed
{
    public function __construct(public ShoppingCart $cart) {}
}
