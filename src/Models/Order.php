<?php

declare(strict_types=1);

namespace Marshmallow\Ecommerce\Cart\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Marshmallow\Addressable\Traits\Addressable;
use Marshmallow\Ecommerce\Cart\Concerns\CalculatesTotals;
use Marshmallow\Ecommerce\Cart\Enums\OrderStatus;
use Marshmallow\Ecommerce\Cart\Events\OrderCreated;
use Marshmallow\Ecommerce\Cart\Exceptions\PurchasableUnavailableException;

/**
 * A placed order: an immutable financial record derived from a paid cart.
 *
 * @property string $shopping_cart_id
 * @property int $shopping_cart_display_id
 * @property int|null $customer_id
 * @property int|null $user_id
 * @property int|null $shipping_address_id
 * @property int|null $invoice_address_id
 * @property int|null $shipping_method_id
 * @property string|null $note
 * @property string $currency
 * @property OrderStatus $status
 * @property Collection<int, OrderItem> $items
 */
class Order extends Model
{
    use Addressable;
    use CalculatesTotals;
    use HasFactory;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'shipped_at' => 'datetime',
        ];
    }

    /**
     * Create (or return the existing) order for a cart. Idempotent on the
     * cart's id, so a webhook that fires twice never creates a second order.
     */
    public static function createFromShoppingCart(ShoppingCart $cart): Order
    {
        return DB::transaction(function () use ($cart): Order {
            $orderModel = config('cart.models.order');

            if ($existing = $orderModel::where('shopping_cart_id', $cart->id)->first()) {
                return $existing;
            }

            $cart->loadMissing('items');
            static::assertItemsAvailable($cart);

            $customer = $cart->customer ?? $cart->prospect?->convertToCustomer();

            $order = $orderModel::create([
                'shopping_cart_id' => $cart->id,
                'shopping_cart_display_id' => $cart->display_id,
                'customer_id' => $customer?->id,
                'user_id' => $cart->user_id,
                'shipping_address_id' => $cart->shipping_address_id,
                'invoice_address_id' => $cart->invoice_address_id ?? $cart->shipping_address_id,
                'shipping_method_id' => $cart->shipping_method_id,
                'note' => $cart->note,
                'currency' => static::currencyFromCart($cart),
                'status' => OrderStatus::Pending,
                'subtotal_excluding_vat' => $cart->getSubtotalWithoutVat(),
                'subtotal_including_vat' => $cart->getSubtotal(),
                'subtotal_vat_amount' => $cart->getSubtotal() - $cart->getSubtotalWithoutVat(),
                'shipping_excluding_vat' => $cart->getShippingAmountWithoutVat(),
                'shipping_including_vat' => $cart->getShippingAmount(),
                'shipping_vat_amount' => $cart->getShippingVatAmount(),
                'discount_excluding_vat' => $cart->getDiscountAmountWithoutVat(),
                'discount_including_vat' => $cart->getDiscountAmount(),
                'discount_vat_amount' => $cart->getDiscountVatAmount(),
                'total_excluding_vat' => $cart->getTotalAmountWithoutVat(),
                'total_including_vat' => $cart->getTotalAmount(),
                'total_vat_amount' => $cart->getTotalVatAmount(),
            ]);

            if ($customer) {
                $cart->forceFill(['customer_id' => $customer->id])->saveQuietly();
            }

            foreach ($cart->items as $item) {
                $order->items()->create([
                    'shopping_cart_item_id' => $item->id,
                    'purchasable_id' => $item->purchasable_id,
                    'description' => $item->description,
                    'type' => $item->type,
                    'quantity' => $item->quantity,
                    'price_excluding_vat' => $item->price_excluding_vat,
                    'price_including_vat' => $item->price_including_vat,
                    'vat_amount' => $item->vat_amount,
                    'vat_percentage' => $item->vat_percentage,
                    'currency' => $item->currency,
                    'meta' => $item->meta,
                    'visible_in_cart' => $item->visible_in_cart,
                ]);
            }

            event(new OrderCreated($order));

            return $order;
        });
    }

    protected static function assertItemsAvailable(ShoppingCart $cart): void
    {
        if (! config('cart.stock.check_on_checkout', true)) {
            return;
        }

        foreach ($cart->productItems() as $item) {
            $purchasable = $item->resolvePurchasable();

            if ($purchasable && ! $purchasable->isAvailableForPurchase($item->quantity, $cart)) {
                throw PurchasableUnavailableException::for($purchasable, $item->quantity);
            }
        }
    }

    protected static function currencyFromCart(ShoppingCart $cart): string
    {
        $currency = $cart->items->pluck('currency')->first();

        return is_string($currency) ? $currency : (string) config('cart.currency', 'EUR');
    }

    public function isPending(): bool
    {
        return $this->status === OrderStatus::Pending;
    }

    public function isCanceled(): bool
    {
        return $this->status === OrderStatus::Canceled;
    }

    public function isCompleted(): bool
    {
        return $this->status === OrderStatus::Completed;
    }

    public function markAsPending(): void
    {
        $this->setStatus(OrderStatus::Pending);
    }

    public function markAsCanceled(): void
    {
        $this->setStatus(OrderStatus::Canceled);
    }

    public function markAsCompleted(): void
    {
        $this->setStatus(OrderStatus::Completed);
    }

    protected function setStatus(OrderStatus $status): void
    {
        $this->status = $status;
        $this->saveQuietly();
    }

    public function scopePending(Builder $query): void
    {
        $query->where('status', OrderStatus::Pending);
    }

    public function scopeCanceled(Builder $query): void
    {
        $query->where('status', OrderStatus::Canceled);
    }

    public function scopeCompleted(Builder $query): void
    {
        $query->where('status', OrderStatus::Completed);
    }

    public function items(): HasMany
    {
        return $this->hasMany(config('cart.models.order_item'));
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(config('cart.models.customer'));
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(config('cart.models.user'));
    }

    public function shippingMethod(): BelongsTo
    {
        return $this->belongsTo(config('cart.models.shipping_method'));
    }

    public function cart(): BelongsTo
    {
        return $this->belongsTo(config('cart.models.shopping_cart'), 'shopping_cart_id');
    }

    public function shippingAddress(): BelongsTo
    {
        return $this->belongsTo(config('cart.models.address'), 'shipping_address_id');
    }

    public function invoiceAddress(): BelongsTo
    {
        return $this->belongsTo(config('cart.models.address'), 'invoice_address_id');
    }
}
