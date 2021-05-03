<?php

namespace Marshmallow\Ecommerce\Cart\Nova;

use App\Nova\Resource;
use Laravel\Nova\Fields\ID;
use Illuminate\Http\Request;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Fields\HasMany;
use Laravel\Nova\Fields\BelongsTo;
use Laravel\Nova\Fields\BelongsToMany;

class Customer extends Resource
{
    public static $group = 'Customers';

    public static $group_icon = '<svg class="sidebar-icon" viewBox="0 0 20 20" version="1.1" xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink"><g id="Page-1" stroke="none" stroke-width="1" fill="none" fill-rule="evenodd"><g id="icon-shape"><path fill="var(--sidebar-icon)" d="M12,16 L9,16 L11,11.5 L11,8.99791312 C11,7.89449617 11.8982606,7 12.9979131,7 L15.0020869,7 C16.1055038,7 17,7.89826062 17,8.99791312 L17,11.5 L19,16 L16,16 L16,20 L12,20 L12,16 Z M7,13 L9,13 L9,8.99791312 C9,7.89826062 8.10541955,7 7.00189865,7 L2.99810135,7 C1.88670635,7 1,7.89449617 1,8.99791312 L1,13 L3,13 L3,20 L7,20 L7,13 Z M5,6 C6.65685425,6 8,4.65685425 8,3 C8,1.34314575 6.65685425,0 5,0 C3.34314575,0 2,1.34314575 2,3 C2,4.65685425 3.34314575,6 5,6 Z M14,6 C15.6568542,6 17,4.65685425 17,3 C17,1.34314575 15.6568542,0 14,0 C12.3431458,0 11,1.34314575 11,3 C11,4.65685425 12.3431458,6 14,6 Z" id="Combined-Shape"></path>';

    /**
     * The model the resource corresponds to.
     *
     * @var string
     */
    public static $model = 'Marshmallow\Ecommerce\Cart\Models\Customer';

    /**
     * The single value that should be used to represent the resource when being displayed.
     *
     * @var string
     */
    public static $title = 'first_name';

    /**
     * The columns that should be searched.
     *
     * @var array
     */
    public static $search = [
        'first_name', 'last_name',
    ];

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
            BelongsTo::make('Country', 'country', config('cart.nova.resources.country'))->sortable()->nullable(),
            Text::make(__('Email'), 'email')->sortable(),
            Text::make(__('Phone number'), 'phone_number')->sortable(),

            HasMany::make(__('Orders'), 'orders', config('cart.nova.resources.order')),
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
