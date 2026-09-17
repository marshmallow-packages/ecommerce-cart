<?php

declare(strict_types=1);

namespace Marshmallow\Ecommerce\Cart\Events;

use Illuminate\Database\Eloquent\Model;
use Marshmallow\Ecommerce\Cart\Models\ShoppingCart;

/**
 * A payment reported paid, but the amount settled does not match the
 * snapshot it was started with. No order was created; the host has to look.
 */
final class PaymentSnapshotMismatch
{
    public function __construct(
        public ShoppingCart $cart,
        public Model $payment,
        public int $expectedAmount,
        public int $paidAmount,
    ) {}
}
