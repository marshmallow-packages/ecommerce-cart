<?php

declare(strict_types=1);

namespace Marshmallow\Ecommerce\Cart;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Marshmallow\Ecommerce\Cart\Models\ShoppingCart;

/**
 * The entry point behind the Cart facade: it resolves the current cart from the
 * session and exposes it to the request.
 */
class Cart
{
    public const REQUEST_KEY = 'cart';

    public function addToRequest(Request $request, ShoppingCart $cart): Request
    {
        $request->attributes->set(self::REQUEST_KEY, $cart);

        return $request;
    }

    public function getFromRequest(): ?ShoppingCart
    {
        $cart = request()->attributes->get(self::REQUEST_KEY);

        return $cart instanceof ShoppingCart ? $cart : null;
    }

    public function getUserGuard(): string
    {
        return (string) (config('cart.customer_guard') ?: Auth::getDefaultDriver());
    }

    /**
     * The current cart, creating a fresh one if the session has none.
     */
    public function get(): ShoppingCart
    {
        $cartModel = config('cart.models.shopping_cart');

        return $cartModel::getBySession() ?? $cartModel::completelyNew();
    }
}
