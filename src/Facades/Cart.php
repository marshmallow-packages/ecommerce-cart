<?php

declare(strict_types=1);

namespace Marshmallow\Ecommerce\Cart\Facades;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;
use Marshmallow\Ecommerce\Cart\Models\ShoppingCart;

/**
 * @method static Request addToRequest(Request $request, ShoppingCart $cart)
 * @method static ShoppingCart|null getFromRequest()
 * @method static string getUserGuard()
 * @method static ShoppingCart get()
 *
 * @see \Marshmallow\Ecommerce\Cart\Cart
 */
class Cart extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Marshmallow\Ecommerce\Cart\Cart::class;
    }
}
