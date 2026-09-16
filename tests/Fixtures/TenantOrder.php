<?php

declare(strict_types=1);

namespace Marshmallow\Ecommerce\Cart\Tests\Fixtures;

use Marshmallow\Ecommerce\Cart\Models\Order;

class TenantOrder extends Order
{
    protected $table = 'orders';
}
