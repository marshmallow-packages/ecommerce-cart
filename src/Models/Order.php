<?php

declare(strict_types=1);

namespace Marshmallow\Ecommerce\Cart\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Marshmallow\Addressable\Traits\Addressable;
use Marshmallow\Ecommerce\Cart\Concerns\CalculatesTotals;
use Marshmallow\Ecommerce\Cart\Contracts\Purchasable;
use Marshmallow\Ecommerce\Cart\Enums\CartItemType;
use Marshmallow\Ecommerce\Cart\Enums\OrderStatus;
use Marshmallow\Ecommerce\Cart\Events\OrderCreated;
use Marshmallow\Ecommerce\Cart\Events\OrderRefunded;
use Marshmallow\Ecommerce\Cart\Exceptions\EmptyCartException;
use Marshmallow\Ecommerce\Cart\Exceptions\InvalidOrderStatusTransitionException;
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
    use SoftDeletes;

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
     *
     * {@see OrderCreated} fires *after* the transaction commits. Its listeners
     * mail the confirmation, which means touching the queue: a queue that is
     * unreachable must never roll back an order the customer already paid for.
     * The event is skipped for a cart that was already converted, so a second
     * webhook does not mail a second confirmation either.
     */
    public static function createFromShoppingCart(ShoppingCart $cart): Order
    {
        /** @var array{0: Order, 1: bool} $result */
        $result = DB::transaction(function () use ($cart): array {
            /** @var class-string<Order> $orderModel */
            $orderModel = config('cart.models.order');

            // Without global scopes on purpose: an application can hide a
            // not-yet-finalised order behind a scope (a site scope, say), and
            // missing an existing one here would breach the unique
            // shopping_cart_id constraint on the second webhook. Carry a
            // customer back to the still-locked cart with a quiet write.
            if ($existing = $orderModel::query()->withoutGlobalScopes()->where('shopping_cart_id', $cart->id)->first()) {
                if ($existing->customer_id && ! $cart->customer_id) {
                    $cart->forceFill(['customer_id' => $existing->customer_id])->saveQuietly();
                }

                return [$existing, false];
            }

            $cart->loadMissing('items');

            if ($cart->productItems()->isEmpty()) {
                throw EmptyCartException::make();
            }

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

            return [$order, true];
        });

        [$order, $created] = $result;

        if ($created) {
            event(new OrderCreated($order));
        }

        return $order;
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
        $this->transitionTo(OrderStatus::Pending);
    }

    public function markAsCanceled(): void
    {
        $this->transitionTo(OrderStatus::Canceled);
    }

    public function markAsCompleted(): void
    {
        $this->transitionTo(OrderStatus::Completed);
    }

    public function isRefunded(): bool
    {
        return $this->status === OrderStatus::Refunded;
    }

    /**
     * {@see OrderRefunded} fires once: a second call for an order that is
     * already refunded (a webhook retry, say) changes and announces nothing.
     */
    public function markAsRefunded(): void
    {
        if ($this->transitionTo(OrderStatus::Refunded)) {
            event(new OrderRefunded($this));
        }
    }

    public function scopeRefunded(Builder $query): void
    {
        $query->where('status', OrderStatus::Refunded);
    }

    /**
     * Start a fresh cart holding this order's products again, at today's
     * prices and availability. Lines whose product has since vanished or sold
     * out are simply left off; the customer sees today's truth, not a replay
     * of the old receipt. The new cart becomes the session's cart.
     */
    public function toNewCart(): ShoppingCart
    {
        $cart = config('cart.models.shopping_cart')::completelyNew();

        foreach ($this->items as $item) {
            if ($item->type !== CartItemType::Product) {
                continue;
            }

            $purchasable = $item->purchasable()->first();

            if (! $purchasable instanceof Purchasable) {
                continue;
            }

            if (! $purchasable->isAvailableForPurchase($item->quantity, $cart)) {
                continue;
            }

            $cart->add($purchasable, $item->quantity, $item->meta);
        }

        return $cart;
    }

    /**
     * Move the order to a status, quietly. Returns false when the order is
     * already there, and throws when {@see OrderStatus::canTransitionTo()}
     * rules the move out.
     */
    protected function transitionTo(OrderStatus $status): bool
    {
        $from = $this->status ?? OrderStatus::Pending;

        if ($from === $status) {
            return false;
        }

        if (! $from->canTransitionTo($status)) {
            throw InvalidOrderStatusTransitionException::make($from, $status);
        }

        $this->status = $status;
        $this->saveQuietly();

        return true;
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
        return $this->hasMany(config('cart.models.order_item'), 'order_id');
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
