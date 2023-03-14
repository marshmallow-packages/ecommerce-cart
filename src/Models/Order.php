<?php

namespace Marshmallow\Ecommerce\Cart\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Marshmallow\Ecommerce\Cart\Traits\Totals;
use Marshmallow\Addressable\Traits\Addressable;
use Marshmallow\Ecommerce\Cart\Events\OrderCreated;
use Marshmallow\Ecommerce\Cart\Traits\PriceFormatter;
use Marshmallow\Ecommerce\Cart\Models\ShoppingCartItem;

class Order extends Model
{
    use Totals;
    use Addressable;
    use PriceFormatter;

    public const STATUS_PENDING = 'PENDING';
    public const STATUS_CANCELED = 'CANCELED';
    public const STATUS_COMPLETED = 'COMPLETED';

    protected $guarded = [];

    protected $casts = [
        'shipped_at' => 'datetime',
    ];

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
            $prospect = config('cart.models.prospect')::withTrashed()->find($shoppingCart->prospect_id);
        }

        $customer = $shoppingCart->customer ?? $prospect->convertToCustomer();

        /**
         * Convert the address so the are connected to the customer
         * instead of the prospect.
         */
        $ignore_columns = ['id', 'addressable_type', 'addressable_id', 'created_at', 'updated_at', 'deleted_at'];

        if ($shipping_address = $shoppingCart->shippingAddress()->withTrashed()->first()) {

            /**
             * Connect the address to the customer if we are dealing with a prospect.
             */
            if ($shipping_address->addressable_type == config('cart.models.prospect')) {

                $prospect_shipping_address = collect(
                    $shipping_address->toArray()
                )
                    ->except($ignore_columns)
                    ->toArray();

                $shipping_address = $customer->addresses()->create($prospect_shipping_address);
            }

            $invoice_address = $shipping_address;

            /**
             * If there is another address for invoice, we need to create
             * another one.
             */
            if ($shoppingCart->shipping_address_id != $shoppingCart->invoice_address_id) {

                $invoice_address = $shoppingCart->invoiceAddress()->withTrashed()->first();

                if ($invoice_address->addressable_type == config('cart.models.prospect')) {
                    $prospect_invoice_address = collect(
                        $invoice_address->toArray()
                    )
                        ->except($ignore_columns)
                        ->toArray();

                    $invoice_address = $customer->addresses()->create($prospect_invoice_address);
                }
            }


            /**
             * Delete the address of the prospect. We will be deleting
             * the prospect as well because it's not a prospect anymore.
             */
            $prospect->addresses->each(function ($address) {
                $address->delete();
            });
            $prospect->delete();
        }


        /**
         * Create the order
         */
        $order = config('cart.models.order')::updateOrCreate([
            'shopping_cart_id' => $shoppingCart->id,
        ], [
            'shopping_cart_id' => $shoppingCart->id,
            'shopping_cart_display_id' => $shoppingCart->display_id,
            'customer_id' => $customer->id,
            'user_id' => $shoppingCart->user_id,
            'shipping_address_id' => (isset($shipping_address) && $shipping_address) ? $shipping_address->id : null,
            'invoice_address_id' => (isset($invoice_address) && $invoice_address) ? $invoice_address->id : null,
            'shipping_method_id' => config('cart.models.shipping_method')::first()?->id,
            'note' => $shoppingCart->note,
            'currency_id' => config('cart.models.currency')::first()->id,
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

        if ($customer && !$shoppingCart->customer_id) {
            $shoppingCart->updateQuietly([
                'customer_id' => $customer->id
            ]);
        }

        /**
         * Add the shopping cart items to the order
         */
        $shoppingCart->items->each(function ($item) use ($order) {

            $item_created = config('cart.models.order_item')::where('order_id', $order->id)
                ->where('shopping_cart_item_id', $item->id)
                ->first();

            /**
             * Check if this item is already created.
             */
            if (!$item_created) {

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

                config('cart.models.order_item')::updateOrCreate([
                    'order_id' => $order->id,
                    'shopping_cart_item_id' => $item->id,
                ], $data);
            }
        });

        if ($order->wasRecentlyCreated) {
            event(new OrderCreated($order));
        }

        return $order;
    }

    public function getShippedAtDateAsString(string $format = 'Y-m-d')
    {
        if ($this->shipped_at) {
            return $this->shipped_at->format($format);
        }
        return __('Not yet');
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
        return config('cart.models.address')::where('id', $this->shipping_address_id)->withTrashed()->first();
    }

    public function invoiceAddress()
    {
        return config('cart.models.address')::where('id', $this->invoice_address_id)->withTrashed()->first();
    }

    public function getShippingItem()
    {
        return $this->items()->where('type', ShoppingCartItem::TYPE_SHIPPING)->first();
    }

    public function scopePending(Builder $builder)
    {
        $builder->where(function (Builder $builder) {
            $builder->whereNull('status')
                ->orwhere('status', self::STATUS_PENDING);
        });
    }

    public function scopeCanceled(Builder $builder)
    {
        $builder->where('status', self::STATUS_CANCELED);
    }

    public function scopeCompleted(Builder $builder)
    {
        $builder->where('status', self::STATUS_COMPLETED);
    }

    public function visibleItems()
    {
        return self::items()->visable()->get();
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

    public function cart()
    {
        return $this->belongsTo(
            config('cart.models.shopping_cart'),
            'shopping_cart_id'
        );
    }
}
