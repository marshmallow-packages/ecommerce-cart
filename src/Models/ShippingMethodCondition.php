<?php

namespace Marshmallow\Ecommerce\Cart\Models;

use Illuminate\Database\Eloquent\Model;
use Marshmallow\HelperFunctions\Traits\Observer;
use Marshmallow\Ecommerce\Cart\Models\ShippingMethod;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class ShippingMethodCondition extends Model
{
    use Observer;
    use HasFactory;

    protected $guarded = [];

    public function method()
    {
        return $this->belongsTo(config('cart.models.shipping_method'), 'shipping_method_id');
    }

    /**
     * This will make sure that the submitted amount in Nova
     * is multiplied by 100 so we can store it in cents.
     * @param [type] $amount [description]
     */
    protected function setMinimumAmountAttribute(float $amount)
    {
        $this->attributes['minimum_amount'] = $amount * 100;
    }

    protected function setMaximumAmountAttribute(float $amount)
    {
        $this->attributes['maximum_amount'] = $amount * 100;
    }

    /**
     * Observer will make sure the "hidden" columns
     * will be filled when creating or updating
     * a price.
     */
    public static function getObserver(): string
    {
        return '';
    }

    public function __saving()
    {
        if (config('priceable.nova.prices_are_including_vat')) {

            /**
             * The added price is including the VAT. We need to calculate
             * the price without the VAT.
             */
            $minimum_amount_excluding_vat = ($this->minimum_amount / (100 + $this->method->vatrate->rate)) * 100;
            $maximum_amount_excluding_vat = ($this->maximum_amount / (100 + $this->method->vatrate->rate)) * 100;
        } else {
            $minimum_amount_excluding_vat = $this->minimum_amount;
            $maximum_amount_excluding_vat = $this->maximum_amount;
        }

        $this->minimum_amount_excluding_vat = $minimum_amount_excluding_vat;
        $this->maximum_amount_excluding_vat = $maximum_amount_excluding_vat;

        $this->minimum_amount_including_vat = $minimum_amount_excluding_vat * $this->method->vatrate->multiplier();
        $this->maximum_amount_including_vat = $maximum_amount_excluding_vat * $this->method->vatrate->multiplier();
    }
}
