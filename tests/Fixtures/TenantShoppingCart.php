<?php

declare(strict_types=1);

namespace Marshmallow\Ecommerce\Cart\Tests\Fixtures;

use Marshmallow\Ecommerce\Cart\Models\ShoppingCart;

/**
 * A host-application subclass of the cart, as the README promises is possible
 * through `config('cart.models.shopping_cart')`.
 */
class TenantShoppingCart extends ShoppingCart
{
    protected $table = 'shopping_carts';

    public function tenantLabel(): string
    {
        return 'tenant:'.$this->display_id;
    }
}
