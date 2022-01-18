<?php

namespace Marshmallow\Ecommerce\Cart\Helpers;

use Laravel\Nova\Fields\Select;

class DiscountProductCategorySelector
{
    public static function make()
    {
        return Select::make(__('Category'), 'category')->options(
            self::options()
        );
    }

    public static function options()
    {
        return config('cart.models.customer')::get()->pluck('name', 'id')->toArray();
    }
}
