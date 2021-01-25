<?php

namespace Marshmallow\Ecommerce\Cart;

use Illuminate\Support\Facades\Auth;
use Marshmallow\Ecommerce\Cart\Models\ShoppingCart;

class Cart
{
    public function getUserGuard()
    {
        if ($guard = config('cart.customer_guard')) {
            return $guard;
        }

        return Auth::getDefaultDriver();
    }

    public function get(): ShoppingCart
    {
        if ($cart = ShoppingCart::getBySession()) {
            return $cart;
        }

        return ShoppingCart::completelyNew();
    }

    public function layouts ()
    {
        return [
            'ecommerce-product-overview' => \Marshmallow\Ecommerce\Cart\Flexible\Layouts\EcommerceProductOverviewLayout::class,
        ];
    }
}
