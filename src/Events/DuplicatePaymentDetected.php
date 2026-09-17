<?php

declare(strict_types=1);

namespace Marshmallow\Ecommerce\Cart\Events;

use Illuminate\Database\Eloquent\Model;
use Marshmallow\Ecommerce\Cart\Models\Order;

/**
 * A second payment settled for a cart that already became an order through
 * another payment. The existing order stands; the host should refund this
 * payment.
 */
final class DuplicatePaymentDetected
{
    public function __construct(
        public Order $order,
        public Model $payment,
    ) {}
}
