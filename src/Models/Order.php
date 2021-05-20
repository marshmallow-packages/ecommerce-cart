<?php

namespace Marshmallow\Ecommerce\Cart\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Marshmallow\Priceable\Models\Currency;
use Marshmallow\Addressable\Models\Address;
use Marshmallow\Ecommerce\Cart\Traits\Totals;
use Marshmallow\Addressable\Traits\Addressable;
use Marshmallow\Ecommerce\Cart\Events\OrderCreated;
use Marshmallow\Ecommerce\Cart\Models\ShippingMethod;
use Marshmallow\Ecommerce\Cart\Traits\PriceFormatter;

class Order extends Model
{
    use Totals;
    use Addressable;
    use PriceFormatter;

    public const STATUS_PENDING = 'PENDING';
    public const STATUS_CANCELED = 'CANCELED';
    public const STATUS_COMPLETED = 'COMPLETED';

    protected $guarded = [];

    public static function createUniqueFromShoppingCart(ShoppingCart $shoppingCart)
    {
        /**
         * Check if its already converted.
         */
        $order = self::where('shopping_cart_id', $shoppingCart->id)->first();
        if ($order) {
            return $order;
        }

        /**
         * Convert the prospect to a customer
         */
        $prospect = $shoppingCart->prospect;
        if (!$prospect) {
            $prospect = Prospect::withTrashed()->find($shoppingCart->prospect_id);
        }

        $customer = $shoppingCart->customer ?? $prospect->convertToCustomer();

        /**
         * Convert the address so the are connected to the customer
         * instead of the prospect.
         */
        $ignore_columns = ['id', 'addressable_type', 'addressable_id', 'created_at', 'updated_at', 'deleted_at'];

        $prospect_shipping_address = collect(
            $shoppingCart->shippingAddress()->withTrashed()->first()->toArray()
        )
            ->except($ignore_columns)
            ->toArray();

        $shipping_address = $customer->addresses()->create($prospect_shipping_address);
        $invoice_address = $shipping_address;

        /**
         * If there is another address for invoice, we need to create
         * another one.
         */
        if ($shoppingCart->shipping_address_id != $shoppingCart->invoice_address_id) {
            $prospect_invoice_address = collect(
                $shoppingCart->invoiceAddress()->withTrashed()->first()->toArray()
            )
                ->except($ignore_columns)
                ->toArray();

            $invoice_address = $customer->addresses()->create($prospect_invoice_address);
        }

        /**
         * Delete the address of the prospect. We will be deleting
         * the prospect as well because it's not a prospect anymore.
         */
        $prospect->addresses->each(function ($address) {
            $address->delete();
        });
        $prospect->delete();


        /**
         * Create the order
         */
        $order = Order::updateOrCreate([
            'shopping_cart_id' => $shoppingCart->id,
        ], [
            'shopping_cart_id' => $shoppingCart->id,
            'shopping_cart_display_id' => $shoppingCart->display_id,
            'customer_id' => $customer->id,
            'user_id' => $shoppingCart->user_id,
            'shipping_address_id' => $shipping_address->id,
            'invoice_address_id' => $invoice_address->id,
            'shipping_method_id' => ShippingMethod::first()->id,
            'note' => $shoppingCart->note,
            'currency_id' => Currency::first()->id,
            'display_price' => $shoppingCart->getTotalAmount(),
            'price_excluding_vat' => $shoppingCart->getTotalAmountWithoutVat(),
            'price_including_vat' => $shoppingCart->getTotalAmountIncludingVat(),
            'vat_amount' => $shoppingCart->getTotalVatAmount(),
            'display_discount' => 0,
            'discount_excluding_vat' => 0,
            'discount_including_vat' => 0,
            'discount_vat_amount' => 0,
            'display_shipping' => $shoppingCart->getShippingAmount(),
            'shipping_excluding_vat' => $shoppingCart->getShippingAmountWithoutVat(),
            'shipping_including_vat' => $shoppingCart->getShippingAmountIncludingVat(),
            'shipping_vat_amount' => $shoppingCart->getShippingVatAmount(),
        ]);

        if ($order->wasRecentlyCreated) {
            event(new OrderCreated($order));
        }

        /**
         * Add the shopping cart items to the order
         */
        $shoppingCart->items->each(function ($item) use ($order) {
            $data = [
                'order_id' => $order->id,
                'shopping_cart_item_id' => $item->id,
                'product_id' => $item->product_id,
                'vatrate_id' => $item->vatrate_id,
                'currency_id' => $item->currency_id,
                'description' => $item->description,
                'quantity' => $item->quantity,
                'type' => $item->type,
                'display_price' => $item->display_price,
                'price_excluding_vat' => $item->price_excluding_vat,
                'price_including_vat' => $item->price_including_vat,
                'vat_amount' => $item->vat_amount,
                'display_discount' => 0,
                'discount_excluding_vat' => 0,
                'discount_including_vat' => 0,
                'discount_vat_amount' => 0,
                'visible_in_cart' => $item->visible_in_cart,
            ];

            OrderItem::updateOrCreate([
                'order_id' => $order->id,
                'shopping_cart_item_id' => $item->id,
            ], $data);
        });

        return $order;
    }

    public function isPending()
    {
        return ($this->status == self::STATUS_PENDING);
    }

    public function isCanceled()
    {
        return ($this->status == self::STATUS_CANCELED);
    }

    public function isCompleted()
    {
        return ($this->status == self::STATUS_COMPLETED);
    }

    public function markAsPending()
    {
        $this->setStatus(self::STATUS_PENDING);
    }

    public function markAsCanceled()
    {
        $this->setStatus(self::STATUS_CANCELED);
    }

    public function markAsCompleted()
    {
        $this->setStatus(self::STATUS_COMPLETED);
    }

    protected function setStatus(string $status)
    {
        $this->status = $status;
        $this->saveQuietly();
    }

    public function shippingAddress()
    {
        return config('cart.models.address')::find($this->shipping_address_id);
    }

    public function invoiceAddress()
    {
        return config('cart.models.address')::find($this->invoice_address_id);
    }

    public function scopePending(Builder $builder)
    {
        $builder->where('status', self::STATUS_PENDING);
    }

    public function scopeCanceled(Builder $builder)
    {
        $builder->where('status', self::STATUS_CANCELED);
    }

    public function scopeCompleted(Builder $builder)
    {
        $builder->where('status', self::STATUS_COMPLETED);
    }

    public function items()
    {
        return $this->hasMany(
            config('cart.models.order_item')
        );
    }

    public function customer()
    {
        return $this->belongsTo(
            config('cart.models.customer')
        );
    }

    public function user()
    {
        return $this->belongsTo(
            config('cart.models.user')
        );
    }

    public function currency()
    {
        return $this->belongsTo(
            config('cart.models.currency')
        );
    }

    public function shippingMethod()
    {
        return $this->belongsTo(
            config('cart.models.shipping_method')
        );
    }
}
