<?php

namespace Marshmallow\Ecommerce\Cart\Models;

use Illuminate\Database\Eloquent\Model;
use Marshmallow\Priceable\Facades\Price;
use Illuminate\Database\Eloquent\Builder;
use Marshmallow\Priceable\Models\VatRate;
use Marshmallow\Priceable\Models\Currency;
use Marshmallow\HelperFunctions\Traits\Observer;
use Marshmallow\Ecommerce\Cart\Models\ShoppingCart;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Marshmallow\HelperFunctions\Traits\ModelHasDefaults;
use Marshmallow\Ecommerce\Cart\Models\ShippingMethodCondition;
use Marshmallow\HelperFunctions\Facades\Builder as BuilderHelper;

class ShippingMethod extends Model
{
    use Observer;
    use HasFactory;
    use ModelHasDefaults;

    protected $casts = [
        'valid_from' => 'datetime',
        'valid_till' => 'datetime',
    ];

    protected $guarded = [];

    /**
     * Publics
     */
    public function getPriceHelper()
    {
        return Price::make(
            $this->vatrate,
            $this->currency,
            $this->display_price,
            ($this->display_price === $this->price_including_vat)
        );
    }


    /**
     * Statics
     */
    public static function calculateFromCart(ShoppingCart $cart): ?ShippingMethod
    {
        $active_methods = self::currentlyActive()->get();

        /**
         * No active shipping method's found so we can stop
         * this method.
         */
        if (!$active_methods) {
            return null;
        }

        $total_price = $cart->getTotalAmountWithoutShipping();
        foreach ($active_methods as $method) {
            $conditions = $method->conditions;

            /**
             * This method doesnt have any conditions so we can go
             * to the next one.
             */
            if (!$conditions) {
                continue;
            }

            foreach ($conditions as $condition) {

                /**
                 * If the shoppingcart is less then the minumum amount
                 * we can continue with the next condition.
                 */
                if ($total_price < $condition->minimum_amount_including_vat) {
                    continue;
                }

                /**
                 * If there is no maximum or the total price is less then the
                 * maximum, we have found a condition that matches.
                 */
                if (!$condition->maximum_amount_including_vat || $total_price < $condition->maximum_amount_including_vat) {
                    return $method;
                }
            }
        }

        return null;
    }

    /**
     * Scopes
     */
    public function scopeCurrentlyActive(Builder $builder)
    {
        BuilderHelper::published($builder);
    }

    /**
     * Relationships
     */
    public function vatrate()
    {
        return $this->belongsTo(VatRate::class);
    }

    public function currency()
    {
        return $this->belongsTo(Currency::class);
    }

    public function conditions()
    {
        return $this->hasMany(ShippingMethodCondition::class);
    }

    /**
     * This will make sure that the submitted amount in Nova
     * is multiplied by 100 so we can store it in cents.
     * @param [type] $amount [description]
     */
    protected function setDisplayPriceAttribute(float $amount)
    {
        $this->attributes['display_price'] = $amount * 100;
    }

    /**
     * For a price we need to make sure we always have
     * a VAT rate and a Currency. Selecting them everytime
     * in Nova is a hassle, therefor we set some default
     * that come from the config.
     * @return array Array with default attributes
     */
    public function defaultAttributes()
    {
        return [
            'vatrate_id' => config('priceable.nova.defaults.vat_rates'),
            'currency_id' => config('priceable.nova.defaults.currencies'),
        ];
    }

    /**
     * Observer will make sure the "hidden" columns
     * will be filled when creating or updating
     * a price.
     */
    public static function getObserver(): string
    {
        return \Marshmallow\Priceable\Observers\PriceObserver::class;
    }

    public function __saving()
    {
        if (config('priceable.nova.prices_are_including_vat')) {

            /**
             * The added price is including the VAT. We need to calculate
             * the price without the VAT.
             */
            $price_excluding_vat = ($this->display_price / (100 + $this->vatrate->rate)) * 100;
        } else {
            $price_excluding_vat = $this->display_price;
        }

        $this->price_excluding_vat = $price_excluding_vat;
        $this->price_including_vat = $price_excluding_vat * $this->vatrate->multiplier();
        $this->vat_amount = $this->price_including_vat - $this->price_excluding_vat;
    }
}
