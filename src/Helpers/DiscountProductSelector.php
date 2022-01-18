<?php

namespace Marshmallow\Ecommerce\Cart\Helpers;

use Laravel\Nova\Fields\Select;

class DiscountProductSelector
{
    public static function make()
    {
        return Select::make(__('Product'), 'product')->options(
            self::options()
        );
    }

    public static function options()
    {
        return config('cart.models.product')::get()->pluck('name', 'id')->toArray();
    }
}
