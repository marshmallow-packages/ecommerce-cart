<?php

namespace Marshmallow\Ecommerce\Cart\Models;

use Illuminate\Support\Str;
use Marshmallow\Priceable\Price;
use Illuminate\Database\Eloquent\Model;
use Marshmallow\Nova\Flexible\Casts\FlexibleCast;
use Marshmallow\Ecommerce\Cart\Models\ShoppingCart;
use Marshmallow\Ecommerce\Cart\Traits\PriceFormatter;
use Marshmallow\Priceable\Facades\Price as PriceFacade;
use Marshmallow\Ecommerce\Cart\Exceptions\DiscountException;

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
        'applies_to_products' => 'array',
        'applies_to_product_categories' => 'array',
        'eligible_for_customers' => 'array',
    ];

    protected static function boot()
    {
        parent::boot();

        static::saving(function ($discount) {
            if ($discount->discount_type != self::TYPE_FIXED_AMOUNT) {
                $discount->fixed_amount = null;
            }

            if ($discount->discount_type != self::TYPE_PERCENTAGE) {
                $discount->percentage_amount = null;
            }

            if ($discount->applies_to == self::APPLIES_TO_CATEGORIES) {
                $categories = $discount->applies_to_product_categories;
                $categories = (is_array($categories)) ? $categories : json_decode($categories);
                if (empty($categories)) {
                    $discount->applies_to = self::APPLIES_TO_ALL;
                }
            }

            if ($discount->applies_to == self::APPLIES_TO_PRODUCTS) {
                $products = $discount->applies_to_products;
                $products = (is_array($products)) ? $products : json_decode($products);
                if (empty($products)) {
                    $discount->applies_to = self::APPLIES_TO_ALL;
                }
            }

            if ($discount->applies_to != self::APPLIES_TO_CATEGORIES) {
                $discount->applies_to_product_categories = null;
            }

            if ($discount->applies_to != self::APPLIES_TO_PRODUCTS) {
                $discount->applies_to_products = null;
            }

            if ($discount->prerequisite_type != self::PREREQUISITE_PURCHASE_AMOUNT) {
                $discount->prerequisite_purchase_amount = null;
            }

            if ($discount->prerequisite_type != self::PREREQUISITE_QUANTITY) {
                $discount->prerequisite_quantity = null;
            }

            if ($discount->eligible_for == self::ELIGIBLE_FOR_CUSTOMERS) {
                $customers = $discount->eligible_for_customers;
                $customers = (is_array($customers)) ? $customers : json_decode($customers);
                if (empty($customers)) {
                    $discount->eligible_for = self::ELIGIBLE_FOR_ALL;
                }
            }

            if ($discount->eligible_for != self::ELIGIBLE_FOR_CUSTOMERS) {
                $discount->eligible_for_customers = null;
            }

            if ($discount->eligible_for != self::ELIGIBLE_FOR_EMAILS) {
                $discount->eligible_for_emails = null;
            }
        });
    }

    public function setFixedAmountAttribute($amount)
    {
        if ($amount) {
            $this->attributes['fixed_amount'] = $amount * 100;
        }
    }

    public function setPrerequisitePurchaseAmountAttribute($amount)
    {
        if ($amount) {
            $this->attributes['prerequisite_purchase_amount'] = $amount * 100;
        }
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
        if (!$this->is_active) {
            throw new DiscountException(__('This voucher is not active yet. Please try again later.'), 1);
        }

        if ($this->starts_at) {
            if ($this->starts_at > now()) {
                throw new DiscountException(__('This voucher is not active yet. Please try again later.'), 1);
            }
        }

        if ($this->ends_at) {
            if ($this->ends_at < now()) {
                throw new DiscountException(__('This voucher is not active anymore. It looks like you are a little to late.'), 1);
            }
        }

        $eligable_items = $this->getEligbleForDiscountItems($cart);
        if (!$eligable_items->count()) {
            throw new DiscountException(__('This voucher can not be used with the items in your shopping cart.'));
        }

        if ($this->prerequisite_type == self::PREREQUISITE_PURCHASE_AMOUNT) {
            $total_amount = 0;
            $eligable_items->each(function ($shopping_cart_item) use (&$total_amount) {
                $total_amount += $shopping_cart_item->getTotalAmount();
            });

            if ($total_amount < $this->prerequisite_purchase_amount) {
                throw new DiscountException(__('This voucher can only be used with a minimum order value of :value.', [
                    'value' => $this->prerequisite_purchase_amount / 100,
                ]));
            }
        } elseif ($this->prerequisite_type == self::PREREQUISITE_QUANTITY) {
            $total_quantity = 0;
            $eligable_items->each(function ($shopping_cart_item) use (&$total_quantity) {
                $total_quantity += $shopping_cart_item->quantity;
            });

            if ($total_quantity < $this->prerequisite_quantity) {
                throw new DiscountException(__('This voucher can only be used if you order at lease :amount of these products.', [
                    'amount' => $this->prerequisite_quantity,
                ]));
            }
        }

        if ($this->eligible_for == self::ELIGIBLE_FOR_CUSTOMERS) {

            $customer = $cart->getCustomer();
            if (get_class($customer) != config('cart.models.customer')) {
                throw new DiscountException(__('This voucher can only be used by some customers. Please log in to your account and try again.'));
            }

            if (!in_array($customer->id, $this->eligible_for_customers)) {
                throw new DiscountException(__('This voucher can only be used by some customers. Sadly, you are not one of them.'));
            }
        } elseif ($this->eligible_for == self::ELIGIBLE_FOR_EMAILS) {

            $customer = $cart->getCustomer();
            if (!in_array($customer->email, $this->eligible_for_emails)) {
                throw new DiscountException(__('This voucher can only be used by some customers. Sadly, you are not one of them.'));
            }
        }

        if ($this->total_usage_limit) {
            $used = OrderItem::where('type', ShoppingCartItem::TYPE_DISCOUNT)->where('description', $this->discount_code)->count();
            if ($used >= $this->total_usage_limit) {
                throw new DiscountException(__('This voucher is at its full capacity. It looks like you are a little to late.'));
            }
        }

        if ($this->is_once_per_customer) {
            $email = $cart->getCustomer()->email;

            $count = OrderItem::join('orders', 'order_items.order_id', '=', 'orders.id')
                ->leftJoin('customers', 'orders.customer_id', '=', 'customers.id')
                ->leftJoin('users', 'orders.user_id', '=', 'users.id')
                ->where('order_items.type', ShoppingCartItem::TYPE_DISCOUNT)
                ->where('order_items.description', $this->discount_code)
                ->where(function ($query) use ($email) {
                    $query->where('customers.email', $email)
                        ->orWhere('users.email', $email);
                })
                ->count();

            if ($count) {
                throw new DiscountException(__('It seams like you have already used this voucher. It is not allowed to use it twice.'));
            }
        }
    }

    public function calculateDiscountFromCart(ShoppingCart $cart): Price
    {
        $vatrate = $this->getVatRate($cart);
        $currency = $this->getCurrency($cart);

        $discount_amount = match ($this->discount_type) {
            self::TYPE_FIXED_AMOUNT => $this->calculateFixedAmountDiscount($cart),
            self::TYPE_PERCENTAGE => $this->calculatePercentageDiscount($cart),
            self::TYPE_FREE_SHIPPING => $this->calculateFreeShippingDiscount($cart),
        };

        $cart_total = $cart->getTotalAmountWithoutShippingAndDiscount();
        $discount_amount = abs($discount_amount);
        $discount_amount = ($discount_amount >= $cart_total) ? $cart_total : $discount_amount;
        $discount_amount = 0 - $discount_amount;

        $display_is_including_vat = true;

        return PriceFacade::make(
            $vatrate,
            $currency,
            $discount_amount,
            $display_is_including_vat
        );
    }

    protected function calculateFixedAmountDiscount(ShoppingCart $cart)
    {
        $fixed_amount = $this->fixed_amount;
        $cart_total = $cart->getTotalAmountWithoutShippingAndDiscount();

        if ($fixed_amount >= $cart_total) {
            return $cart_total;
        }

        return $fixed_amount;
    }

    protected function calculateFreeShippingDiscount(ShoppingCart $cart)
    {
        return $cart->getShippingAmount();
    }

    protected function calculatePercentageDiscount(ShoppingCart $cart)
    {
        $total_amount = 0;
        $elible_for_discount = $this->getEligbleForDiscountItems($cart);
        $elible_for_discount->each(function ($shopping_cart_item) use (&$total_amount) {
            $total_amount += $shopping_cart_item->getTotalAmount();
        });

        if (!$total_amount) {
            return null;
        }

        $discount_amount = ($total_amount / 100) * $this->percentage_amount;
        return round($discount_amount, 2);
    }

    protected function getEligbleForDiscountItems(ShoppingCart $cart)
    {
        $items = $cart->getItemsWithoutDiscountAndShipping();
        if ($this->applies_to == self::APPLIES_TO_CATEGORIES) {
            return $items->reject(function ($item) {
                return !in_array($item->product->product_category_id, $this->applies_to_product_categories);
            });
        } elseif ($this->applies_to == self::APPLIES_TO_PRODUCTS) {
            return $items->reject(function ($item) {
                return !in_array($item->product_id, $this->applies_to_products);
            });
        }

        return $cart->getItemsWithoutDiscountAndShipping();
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
