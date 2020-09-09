<?php 

namespace Marshmallow\Ecommerce\Cart\Facades;

use Illuminate\Support\Facades\Facade;

class Cart extends Facade
{
    protected static function getFacadeAccessor()
    {
        return \Marshmallow\Ecommerce\Cart\Cart::class;
    }
}