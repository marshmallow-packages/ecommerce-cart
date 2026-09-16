<?php

declare(strict_types=1);

namespace Marshmallow\Ecommerce\Cart\Tests\Fixtures;

use Marshmallow\Ecommerce\Cart\Models\ShoppingCartItem;

class TenantShoppingCartItem extends ShoppingCartItem
{
    protected $table = 'shopping_cart_items';
}
