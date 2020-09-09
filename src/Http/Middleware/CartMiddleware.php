<?php

namespace Marshmallow\Ecommerce\Cart\Http\Middleware;
use Closure;
use Marshmallow\Ecommerce\Cart\Models\ShoppingCart;

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
        $cart = ShoppingCart::find($request->session()->get(ShoppingCart::SESSION_KEY));

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
