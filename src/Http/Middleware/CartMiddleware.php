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
        $cart = config('cart.models.shopping_cart')::getBySession();
        if (!$cart) {
            $cart = config('cart.models.shopping_cart')::completelyNew();
        }

        if ($cart->confirmed_at) {
            $cart = config('cart.models.shopping_cart')::newWithSameProspect($cart);
        }

        $request->merge([
            'cart' => $cart,
        ]);

        return $next($request);
    }
}
