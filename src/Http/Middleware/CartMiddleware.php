<?php

namespace Marshmallow\Ecommerce\Cart\Http\Middleware;
use Closure;
use Marshmallow\Ecommerce\Cart\Models\ShoppingCart;
use Marshmallow\Ecommerce\Cart\Http\Resources\ShoppingCartResource;

class CartMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle($request, Closure $next)
    {
        $cart = ShoppingCart::getBySession();
        if (!$cart) {
            $cart = ShoppingCart::completelyNew();
        }

        if ($cart->confirmed_at) {
            $cart = ShoppingCart::newWithSameProspect($cart);
        }

        $request->merge([
            'cart' => $cart,
        ]);

        return $next($request);
    }
}
