<?php

namespace Marshmallow\Ecommerce\Cart;

use Illuminate\Support\Facades\Auth;
use Marshmallow\Ecommerce\Cart\Models\ShoppingCart;

class Cart
{
    public static $productConnection = null;
    public static $currencyConnection = null;

    public function addToRequest($request, $cart)
    {
        $request->attributes->add([
            'cart' => $cart,
        ]);

        return $request;
    }

    public function getFromRequest()
    {
        return request()->attributes->get('cart');
    }

    public function getUserGuard()
    {
        if ($guard = config('cart.customer_guard')) {
            return $guard;
        }

        return Auth::getDefaultDriver();
    }

    public function get(): ShoppingCart
    {
        if ($cart = config('cart.models.shopping_cart')::getBySession()) {
            return $cart;
        }

        return config('cart.models.shopping_cart')::completelyNew();
    }
}
