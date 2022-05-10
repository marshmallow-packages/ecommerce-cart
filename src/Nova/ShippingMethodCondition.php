<?php

namespace Marshmallow\Ecommerce\Cart\Nova;

use App\Nova\Resource;
use Illuminate\Http\Request;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Fields\Currency;
use Laravel\Nova\Fields\BelongsTo;
use Marshmallow\Priceable\Facades\Price;
use Marshmallow\Ecommerce\Cart\Nova\ShippingMethod;
use Marshmallow\Priceable\Nova\Helpers\FieldNameHelper;

class ShippingMethodCondition extends Resource
{
    public static function group()
    {
        return __('Pricing');
    }

    /**
     * Indicates if the resource should be displayed in the sidebar.
     *
     * @var bool
     */
    public static $displayInNavigation = false;

    public static function label()
    {
        return __('Shipping Condition');
    }

    public static function singularLabel()
    {
        return __('Shipping Conditions');
    }

    /**
     * The model the resource corresponds to.
     *
     * @var string
     */
    public static $model = 'Marshmallow\Ecommerce\Cart\Models\ShippingMethodCondition';

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
            BelongsTo::make(__('Shipping Method'), 'method', config('cart.nova.resources.shipping_method')),
            Currency::make(FieldNameHelper::priceLabel('Minimum amount'), 'minimum_amount')->displayUsing(function ($value) {
                return Price::formatAmount($value);
            })->resolveUsing(function ($value) {
                return Price::amount($value);
            })->rules('numeric', 'min:0', 'required'),
            Currency::make(FieldNameHelper::priceLabel('Maximum amount'), 'maximum_amount')->displayUsing(function ($value) {
                return Price::formatAmount($value);
            })->resolveUsing(function ($value) {
                return Price::amount($value);
            })->rules('numeric', 'min:0', 'required')->required(),
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
