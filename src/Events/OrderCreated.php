<?php

declare(strict_types=1);

namespace Marshmallow\Ecommerce\Cart\Events;

use Marshmallow\Ecommerce\Cart\Models\Order;

final class OrderCreated
{
    public function __construct(public Order $order) {}
}
