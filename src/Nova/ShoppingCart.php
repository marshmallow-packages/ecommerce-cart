<?php

namespace Marshmallow\Ecommerce\Cart\Nova;

use App\Nova\Resource;
use Laravel\Nova\Fields\ID;
use Illuminate\Http\Request;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Fields\DateTime;
use Laravel\Nova\Fields\Textarea;
use Laravel\Nova\Fields\BelongsTo;

class ShoppingCart extends Resource
{
    public static function group()
    {
        return __('Orders');
    }

    public static $group_icon = '<svg class="sidebar-icon" viewBox="0 0 20 20" version="1.1" xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink"><g id="Page-1" stroke="none" stroke-width="1" fill="none" fill-rule="evenodd"><g id="icon-shape"><path fill="var(--sidebar-icon)" d="M11.9321237,9.48208981 C12.110309,10.1493234 11.9376722,10.8907549 11.4142136,11.4142136 C10.633165,12.1952621 9.36683502,12.1952621 8.58578644,11.4142136 C7.80473785,10.633165 7.80473785,9.36683502 8.58578644,8.58578644 C9.10924511,8.06232776 9.8506766,7.88969103 10.5179102,8.06787625 L13.5355339,5.05025253 L14.9497475,6.46446609 L11.9321237,9.48208981 Z M15.5995658,15.7135719 C17.0809102,14.261603 18,12.238134 18,10 C18,5.581722 14.418278,2 10,2 C5.581722,2 2,5.581722 2,10 C2,12.238134 2.91908983,14.261603 4.40043425,15.7135719 C5.99810554,14.63183 7.92526686,14 10,14 C12.0747331,14 14.0018945,14.63183 15.5995658,15.7135719 Z M10,20 C15.5228475,20 20,15.5228475 20,10 C20,4.4771525 15.5228475,0 10,0 C4.4771525,0 0,4.4771525 0,10 C0,15.5228475 4.4771525,20 10,20 Z" id="Combined-Shape"></path></g></g></svg>';

    /**
     * The model the resource corresponds to.
     *
     * @var string
     */
    public static $model = 'Marshmallow\Ecommerce\Cart\Models\ShoppingCart';

    /**
     * The single value that should be used to represent the resource when being displayed.
     *
     * @var string
     */
    public static $title = 'id';

    /**
     * The columns that should be searched.
     *
     * @var array
     */
    public static $search = [
        'id',
    ];

    /**
     * Get the fields displayed by the resource.
     *
     * @param  \Laravel\Nova\Http\Requests\NovaRequest $request
     * @return array
     */
    public function fields(NovaRequest $request)
    {
        return [
            ID::make(),
            BelongsTo::make(__('Customer'), 'customer', config('cart.nova.resources.customer'))->searchable()->nullable(),
            BelongsTo::make(__('Prospect'), 'prospect', config('cart.nova.resources.prospect'))->searchable()->nullable(),
            DateTime::make(__('Created At'), 'created_at')->onlyOnIndex(),
            Textarea::make(__('Note'), 'note'),
        ];
    }

    /**
     * Get the cards available for the request.
     *
     * @param  \Laravel\Nova\Http\Requests\NovaRequest $request
     * @return array
     */
    public function cards(NovaRequest $request)
    {
        return [];
    }

    /**
     * Get the filters available for the resource.
     *
     * @param  \Laravel\Nova\Http\Requests\NovaRequest $request
     * @return array
     */
    public function filters(NovaRequest $request)
    {
        return [];
    }

    /**
     * Get the lenses available for the resource.
     *
     * @param  \Laravel\Nova\Http\Requests\NovaRequest $request
     * @return array
     */
    public function lenses(NovaRequest $request)
    {
        return [];
    }

    /**
     * Get the actions available for the resource.
     *
     * @param  \Laravel\Nova\Http\Requests\NovaRequest $request
     * @return array
     */
    public function actions(NovaRequest $request)
    {
        return [];
    }
}
