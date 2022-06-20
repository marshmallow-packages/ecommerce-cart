<?php

namespace Marshmallow\Ecommerce\Cart\Http\Middleware;

use Closure;
use Marshmallow\Ecommerce\Cart\Facades\Cart;
use Marshmallow\HelperFunctions\Facades\URL;

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
        if (URL::isNova($request)) {
            return $next($request);
        }

        $cart = config('cart.models.shopping_cart')::getBySession();
        if (!$cart) {
            $cart_model_class = config('cart.models.shopping_cart');
            $cart = new $cart_model_class;
        }

        if ($cart->confirmed_at) {
            $cart = config('cart.models.shopping_cart')::newWithSameProspect($cart);
        }

        $request = Cart::addToRequest($request, $cart);

        return $next($request);
    }
}
