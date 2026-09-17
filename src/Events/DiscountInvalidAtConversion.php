<?php

declare(strict_types=1);

namespace Marshmallow\Ecommerce\Cart\Events;

use Marshmallow\Ecommerce\Cart\Models\Order;

/**
 * A voucher that was on the cart when the payment started no longer
 * qualified when the order was created. The order keeps the discount as it
 * was paid for; the host decides whether to follow up.
 */
final class DiscountInvalidAtConversion
{
    public function __construct(
        public Order $order,
        public string $code,
        public string $reason,
    ) {}
}
