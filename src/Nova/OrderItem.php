<?php

namespace Marshmallow\Ecommerce\Cart\Nova;

use App\Nova\Resource;
use Eminiarts\Tabs\Tabs;
use Laravel\Nova\Fields\ID;
use Illuminate\Http\Request;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Fields\Number;
use Laravel\Nova\Fields\Heading;
use Laravel\Nova\Fields\DateTime;
use Laravel\Nova\Fields\Textarea;
use Laravel\Nova\Fields\BelongsTo;

class OrderItem extends Resource
{

    public static function group()
    {
        return __('Orders');
    }

    /**
     * Indicates if the resource should be displayed in the sidebar.
     *
     * @var bool
     */
    public static $displayInNavigation = false;

    /**
     * The model the resource corresponds to.
     *
     * @var string
     */
    public static $model = 'Marshmallow\Ecommerce\Cart\Models\OrderItem';

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
            new Tabs('Tabs', [
                'Item'    => [
                    ID::make(),
                    BelongsTo::make(__('Order'), 'order', config('cart.nova.resources.order'))->searchable()->nullable(),
                    BelongsTo::make(__('Product'), 'product', config('cart.nova.resources.product'))->searchable()->nullable(),
                    Text::make(__('Description'), 'description'),
                    Number::make(__('Quantity'), 'quantity'),
                    Text::make(__('Type'), 'type')->hideFromIndex(),
                    DateTime::make(__('Created at'), 'created_at')->hideFromIndex(),
                ],

                'Prices' => [
                    /**
                     * Default prices
                     */
                    BelongsTo::make(__('Currency'), 'currency', config('cart.nova.resources.currency'))->searchable()->nullable()->hideFromIndex(),
                    BelongsTo::make(__('VAT'), 'vatrate', config('cart.nova.resources.vat_rate'))->searchable()->nullable(),
                    Heading::make(__('Total price')),
                    Text::make(__('Price'), 'display_price')->resolveUsing(function ($value, $resource) {
                        return $resource->getFormatted($value);
                    }),
                    Text::make(__('Excl. VAT'), 'price_excluding_vat')->resolveUsing(function ($value, $resource) {
                        return $resource->getFormatted($value);
                    })->hideFromIndex(),
                    Text::make(__('Incl. VAT'), 'price_including_vat')->resolveUsing(function ($value, $resource) {
                        return $resource->getFormatted($value);
                    })->hideFromIndex(),
                    Text::make(__('VAT'), 'vat_amount')->resolveUsing(function ($value, $resource) {
                        return $resource->getFormatted($value);
                    })->hideFromIndex(),

                    /**
                     * Discount prices
                     */
                    Heading::make(__('Discount')),
                    Text::make(__('Discount'), 'display_discount')->resolveUsing(function ($value, $resource) {
                        return $resource->getFormatted($value);
                    }),
                    Text::make(__('Excl. VAT'), 'discount_excluding_vat')->resolveUsing(function ($value, $resource) {
                        return $resource->getFormatted($value);
                    })->hideFromIndex(),
                    Text::make(__('Incl. VAT'), 'discount_including_vat')->resolveUsing(function ($value, $resource) {
                        return $resource->getFormatted($value);
                    })->hideFromIndex(),
                    Text::make(__('VAT'), 'discount_vat_amount')->resolveUsing(function ($value, $resource) {
                        return $resource->getFormatted($value);
                    })->hideFromIndex(),
                ],
            ]),
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
