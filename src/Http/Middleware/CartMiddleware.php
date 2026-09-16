<?php

declare(strict_types=1);

namespace Marshmallow\Ecommerce\Cart\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Marshmallow\Ecommerce\Cart\Facades\Cart;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the current cart for each storefront request and attaches it to the
 * request attributes. A confirmed cart is replaced by a fresh one sharing the
 * same prospect, so a returning customer starts a new order cleanly.
 */
class CartMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($this->isExcluded($request)) {
            return $next($request);
        }

        $cartModel = config('cart.models.shopping_cart');
        $cart = $cartModel::getBySession() ?? new $cartModel;

        if ($cart->confirmed_at) {
            $cart = $cartModel::newWithSameProspect($cart);
        }

        Cart::addToRequest($request, $cart);

        return $next($request);
    }

    protected function isExcluded(Request $request): bool
    {
        $patterns = (array) config('cart.middleware.excluded_paths', []);

        return $patterns !== [] && $request->is(...$patterns);
    }
}
