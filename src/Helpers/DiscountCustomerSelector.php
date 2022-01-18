<?php

namespace Marshmallow\Ecommerce\Cart\Helpers;

use Laravel\Nova\Fields\Select;

class DiscountCustomerSelector
{
    public static function make()
    {
        return Select::make(__('Customer'), 'customer')->options(
            self::options()
        );
    }

    public static function options()
    {
        return config('cart.models.customer')::get()->map(function ($customer) {
            return [
                'name' => $customer->getFullName(),
                'id' => $customer->id,
            ];
        })->pluck('name', 'id')->toArray();
    }
}
