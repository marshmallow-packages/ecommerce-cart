<?php

declare(strict_types=1);

namespace Marshmallow\Ecommerce\Cart\Tests\Fixtures;

use Marshmallow\Ecommerce\Cart\Models\OrderItem;

class TenantOrderItem extends OrderItem
{
    protected $table = 'order_items';
}
