<?php

namespace Marshmallow\Ecommerce\Cart\Nova;

use App\Nova\Resource;
use Laravel\Nova\Fields\ID;
use Illuminate\Http\Request;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Fields\HasMany;
use Laravel\Nova\Fields\BelongsTo;
use Laravel\Nova\Fields\BelongsToMany;
use Marshmallow\Datasets\Country\Nova\Country;

class Prospect extends Resource
{
    public static $group = 'Customers';

    /**
     * The model the resource corresponds to.
     *
     * @var string
     */
    public static $model = 'Marshmallow\Ecommerce\Cart\Models\Prospect';

    /**
     * The columns that should be searched.
     *
     * @var array
     */
    public static $search = [
        'first_name', 'last_name', 'id',
    ];

    /**
     * Get the value that should be displayed to represent the resource.
     *
     * @return string
     */
    public function title()
    {
        return trim($this->first_name . ' ' . $this->last_name) . ' ('. $this->id .')';
    }

    /**
     * Get the fields displayed by the resource.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array
     */
    public function fields(Request $request)
    {
        return [
            Text::make(__('First name'), 'first_name')->sortable(),
            Text::make(__('Last name'), 'last_name')->sortable(),
            Text::make(__('Company name'), 'company_name')->sortable(),
            Text::make(__('Address'), 'address')->sortable(),
            BelongsTo::make(__('Country'), 'country', config('cart.nova.resources.country'))->sortable()->nullable(),
            Text::make(__('Email'), 'email')->sortable(),
            Text::make(__('Phone number'), 'phone_number')->sortable(),

            HasMany::make(__('Shopping cart'), 'cart', config('cart.nova.resources.shopping_cart')),
        ];
    }

    /**
     * Get the cards available for the request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array
     */
    public function cards(Request $request)
    {
        return [];
    }

    /**
     * Get the filters available for the resource.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array
     */
    public function filters(Request $request)
    {
        return [];
    }

    /**
     * Get the lenses available for the resource.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array
     */
    public function lenses(Request $request)
    {
        return [];
    }

    /**
     * Get the actions available for the resource.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array
     */
    public function actions(Request $request)
    {
        return [];
    }
}
