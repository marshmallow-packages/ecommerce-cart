<?php

namespace Marshmallow\Ecommerce\Cart\Nova;

use App\Nova\Currency;
use App\Nova\Resource;
use Illuminate\Http\Request;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Fields\HasMany;
use Laravel\Nova\Fields\DateTime;
use Laravel\Nova\Fields\BelongsTo;
use Marshmallow\Priceable\Nova\VatRate;
use Marshmallow\Priceable\Facades\Price;
use Laravel\Nova\Fields\Currency as NovaCurrencyField;
use Marshmallow\Priceable\Nova\Helpers\FieldNameHelper;
use Marshmallow\Ecommerce\Cart\Nova\ShippingMethodCondition;

class ShippingMethod extends Resource
{
    public static $group = 'Pricing';

    public static function label()
    {
        return __('Shipping Method');
    }

    public static function singularLabel()
    {
        return __('Shipping Method');
    }

    /**
     * The model the resource corresponds to.
     *
     * @var string
     */
    public static $model = 'Marshmallow\Ecommerce\Cart\Models\ShippingMethod';

    /**
     * The single value that should be used to represent the resource when being displayed.
     *
     * @var string
     */
    public static $title = 'name';

    /**
     * The columns that should be searched.
     *
     * @var array
     */
    public static $search = [
        'name',
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
            Text::make(__('Name'), 'name')->sortable(),
            BelongsTo::make(__('Vat rate'), 'vatrate', VatRate::class)->withoutTrashed(),
            BelongsTo::make(__('Currency'), 'currency', Currency::class)->withoutTrashed(),
            NovaCurrencyField::make(FieldNameHelper::priceLabel(), 'display_price')->displayUsing(function ($value) {
                return Price::formatAmount($value);
            })->resolveUsing(function ($value) {
                return Price::amount($value);
            }),
            DateTime::make('Valid from'),
            DateTime::make('Valid till'),

            HasMany::make(__('Conditions'), 'conditions', ShippingMethodCondition::class),
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
