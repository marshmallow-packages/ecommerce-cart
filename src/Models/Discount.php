<?php

namespace Marshmallow\Ecommerce\Cart\Models;

use Illuminate\Support\Str;
use Marshmallow\Priceable\Price;
use Illuminate\Database\Eloquent\Model;
use Marshmallow\Nova\Flexible\Casts\FlexibleCast;
use Marshmallow\Ecommerce\Cart\Models\ShoppingCart;
use Marshmallow\Ecommerce\Cart\Traits\PriceFormatter;
use Marshmallow\Priceable\Facades\Price as PriceFacade;

class Discount extends Model
{
    use PriceFormatter;

    public const TYPE_FIXED_AMOUNT = 'fixed_amount';
    public const TYPE_PERCENTAGE = 'percentage';
    public const TYPE_FREE_SHIPPING = 'free_shipping';

    public const APPLIES_TO_ALL = 'all';
    public const APPLIES_TO_CATEGORIES = 'specific_categories';
    public const APPLIES_TO_PRODUCTS = 'specific_products';

    public const PREREQUISITE_NONE = 'none';
    public const PREREQUISITE_PURCHASE_AMOUNT = 'prerequisite_purchase_amount';
    public const PREREQUISITE_QUANTITY = 'prerequisite_quantity';

    public const ELIGIBLE_FOR_ALL = 'all';
    public const ELIGIBLE_FOR_CUSTOMERS = 'eligible_for_customers';
    public const ELIGIBLE_FOR_EMAILS = 'eligible_for_emails';

    protected $guarded = [];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'is_once_per_customer' => 'boolean',
        'applies_to_products' => FlexibleCast::class,
        'applies_to_product_categories' => FlexibleCast::class,
        'eligible_for_customers' => FlexibleCast::class,
    ];

    protected static function boot()
    {
        parent::boot();

        static::saving(function ($discount) {
            $discount->fixed_amount = $discount->discount_type != self::TYPE_FIXED_AMOUNT ? null : $discount->fixed_amount;
            $discount->percentage_amount = $discount->discount_type != self::TYPE_PERCENTAGE ? null : $discount->percentage_amount;

            $discount->applies_to_product_categories = $discount->applies_to != self::APPLIES_TO_CATEGORIES ? null : $discount->applies_to_product_categories;
            $discount->applies_to_products = $discount->applies_to != self::APPLIES_TO_PRODUCTS ? null : $discount->applies_to_products;

            $discount->prerequisite_purchase_amount = $discount->prerequisite_type != self::PREREQUISITE_PURCHASE_AMOUNT ? null : $discount->prerequisite_purchase_amount;
            $discount->prerequisite_quantity = $discount->prerequisite_type != self::PREREQUISITE_QUANTITY ? null : $discount->prerequisite_quantity;

            $discount->eligible_for_customers = $discount->eligible_for != self::ELIGIBLE_FOR_CUSTOMERS ? null : $discount->eligible_for_customers;
            $discount->eligible_for_emails = $discount->eligible_for != self::ELIGIBLE_FOR_EMAILS ? null : $discount->eligible_for_emails;
        });
    }

    public function getEligibleForEmailsAttribute($email_addresses)
    {
        $email_addresses = is_array($email_addresses) ? $email_addresses : json_decode($email_addresses, true);
        if (!is_array($email_addresses)) {
            return null;
        }

        $email_addresses = array_filter($email_addresses);
        if (empty($email_addresses)) {
            return null;
        }

        return $email_addresses;
    }

    public function setEligibleForEmailsAttribute($email_addresses)
    {
        if (!is_array($email_addresses)) {
            $email_addresses = collect(explode("\n", $email_addresses))->map(function ($email_address) {
                return (string) Str::of($email_address)->replace("\r", '')->trim();
            })->reject(function ($email_address) {
                return !$email_address;
            })->toArray();
        }

        $this->attributes['eligible_for_emails'] = empty($email_addresses) ? null : $email_addresses;
    }

    public static function byCode($code)
    {
        return self::where('discount_code', $code)->firstOrFail();
    }

    public function isAllowed(ShoppingCart $cart): void
    {
        //
    }

    public function calculateDiscountFromCart(ShoppingCart $cart): Price
    {
        $vatrate = $this->getVatRate($cart);
        $currency = $this->getCurrency($cart);

        $display_amount = -100;
        $display_is_including_vat = true;

        return PriceFacade::make(
            $vatrate,
            $currency,
            $display_amount,
            $display_is_including_vat
        );
    }

    public function getVatRate(ShoppingCart $cart)
    {
        return config('cart.models.vat_rate')::find(
            config('cart-discount.default.vat_rate')
        );
    }

    public function getCurrency(ShoppingCart $cart)
    {
        return config('cart.models.currency')::find(
            config('cart-discount.default.currency')
        );
    }
}
