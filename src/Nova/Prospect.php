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
use Marshmallow\Datasets\GoogleProductCategories\Nova\GoogleProductCategory;

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
            Text::make('First name')->sortable(),
            Text::make('Last name')->sortable(),
            Text::make('Company name')->sortable(),
            Text::make('Address')->sortable(),
            BelongsTo::make('Country', 'country', Country::class)->sortable()->nullable(),
            Text::make('Email')->sortable(),
            Text::make('Phone number')->sortable(),

            HasMany::make('ShoppingCart', 'cart'),
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
