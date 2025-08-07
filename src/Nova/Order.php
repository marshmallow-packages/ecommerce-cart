<?php

namespace Marshmallow\Ecommerce\Cart\Nova;

use App\Nova\Resource;
use App\Nova\OrderItem;
use Laravel\Nova\Tabs\Tab;
use Laravel\Nova\Fields\ID;
use Illuminate\Http\Request;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Fields\HasMany;
use Laravel\Nova\Fields\Heading;
use Laravel\Nova\Fields\Currency;
use Laravel\Nova\Fields\DateTime;
use Laravel\Nova\Fields\Textarea;
use Laravel\Nova\Fields\BelongsTo;

class Order extends Resource
{

    public static function group()
    {
        return __('Orders');
    }

    /**
     * The model the resource corresponds to.
     *
     * @var string
     */
    public static $model = 'Marshmallow\Ecommerce\Cart\Models\Order';

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
            Tab::make(__('Order'), [
                ID::make(),
                BelongsTo::make(__('Customer'), 'customer', config('cart.nova.resources.customer'))->searchable()->nullable(),
                BelongsTo::make(__('User'), 'user', config('cart.nova.resources.user'))->searchable()->nullable(),
                DateTime::make(__('Created At'), 'created_at'),
                Text::make(__('Invoice address'))->resolveUsing(function ($value, $resource) {
                    if ($resource->invoiceAddress()) {
                        return $resource->invoiceAddress()->getAsString();
                    }
                })->hideFromIndex(),
                Textarea::make(__('Note'), 'note'),
            ]),
            
            Tab::make(__('Shipping'), [
                Text::make(__('Shipping address'))->resolveUsing(function ($value, $resource) {
                    if ($resource->shippingAddress()) {
                        return $resource->shippingAddress()->getAsString();
                    }
                })->hideFromIndex(),
                BelongsTo::make(__('Type'), 'shippingMethod', config('cart.nova.resources.shipping_method'))->searchable()->nullable(),
                Text::make(__('Track and Trace'), 'track_and_trace'),
                Text::make(__('Status'), 'shipping_status'),
                DateTime::make(__('Shipped at'), 'shipped_at'),
            ]),

            Tab::make(__('Prices'), [
                    /**
                     * Default prices
                     */
                    BelongsTo::make(__('Currency'), 'currency', config('cart.nova.resources.currency'))->searchable()->nullable()->hideFromIndex(),
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
                    })->hideFromIndex(),
                    Text::make(__('Excl. VAT'), 'discount_excluding_vat')->resolveUsing(function ($value, $resource) {
                        return $resource->getFormatted($value);
                    })->hideFromIndex(),
                    Text::make(__('Incl. VAT'), 'discount_including_vat')->resolveUsing(function ($value, $resource) {
                        return $resource->getFormatted($value);
                    })->hideFromIndex(),
                    Text::make(__('VAT'), 'discount_vat_amount')->resolveUsing(function ($value, $resource) {
                        return $resource->getFormatted($value);
                    })->hideFromIndex(),
                    /**
                     * Shipping prices
                     */
                    Heading::make(__('Shipping')),
                    Text::make(__('Price'), 'display_shipping')->resolveUsing(function ($value, $resource) {
                        return $resource->getFormatted($value);
                    })->hideFromIndex(),
                    Text::make(__('Excl. VAT'), 'shipping_excluding_vat')->resolveUsing(function ($value, $resource) {
                        return $resource->getFormatted($value);
                    })->hideFromIndex(),
                    Text::make(__('Incl. VAT'), 'shipping_including_vat')->resolveUsing(function ($value, $resource) {
                        return $resource->getFormatted($value);
                    })->hideFromIndex(),
                    Text::make(__('VAT'), 'shipping_vat_amount')->resolveUsing(function ($value, $resource) {
                        return $resource->getFormatted($value);
                    })->hideFromIndex(),
            ]),

            HasMany::make(__('Items'), 'items', config('cart.nova.resources.order_item')),
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
