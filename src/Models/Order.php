<?php

declare(strict_types=1);

namespace Marshmallow\Ecommerce\Cart\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
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
use Marshmallow\Ecommerce\Cart\Events\DiscountInvalidAtConversion;
use Marshmallow\Ecommerce\Cart\Events\DuplicatePaymentDetected;
use Marshmallow\Ecommerce\Cart\Events\OrderCreated;
use Marshmallow\Ecommerce\Cart\Events\OrderRefunded;
use Marshmallow\Ecommerce\Cart\Events\OrderStatusChanged;
use Marshmallow\Ecommerce\Cart\Events\StockShortageDetected;
use Marshmallow\Ecommerce\Cart\Exceptions\DiscountException;
use Marshmallow\Ecommerce\Cart\Exceptions\EmptyCartException;
use Marshmallow\Ecommerce\Cart\Exceptions\InvalidOrderStatusTransitionException;
use Marshmallow\Ecommerce\Cart\Exceptions\PaymentAmountMismatchException;
use Marshmallow\Ecommerce\Cart\Support\Price;

/**
 * A placed order: an immutable financial record built from the snapshot a
 * payment was started with. Everything the order needs to stand on its own
 * — lines, customer, addresses, shipping method, vouchers — is copied onto
 * it, so later edits to the cart, the address book or the products never
 * change what was sold.
 *
 * @property string $shopping_cart_id
 * @property int $shopping_cart_display_id
 * @property string|null $payment_id
 * @property int|null $customer_id
 * @property int|null $user_id
 * @property int|null $shipping_address_id
 * @property int|null $invoice_address_id
 * @property int|null $shipping_method_id
 * @property string|null $note
 * @property string $currency
 * @property OrderStatus $status
 * @property array<string, mixed>|null $customer_snapshot
 * @property array<string, mixed>|null $shipping_address_snapshot
 * @property array<string, mixed>|null $invoice_address_snapshot
 * @property array<string, mixed>|null $shipping_method_snapshot
 * @property array<int, array<string, mixed>>|null $discounts_snapshot
 * @property string|null $snapshot_fingerprint
 * @property int $subtotal_excluding_vat
 * @property int $subtotal_including_vat
 * @property int $subtotal_vat_amount
 * @property int $shipping_excluding_vat
 * @property int $shipping_including_vat
 * @property int $shipping_vat_amount
 * @property int $discount_excluding_vat
 * @property int $discount_including_vat
 * @property int $discount_vat_amount
 * @property int $total_excluding_vat
 * @property int $total_including_vat
 * @property int $total_vat_amount
 * @property Collection<int, OrderItem> $items
 */
class Order extends Model
{
    use Addressable;
    use CalculatesTotals;
    use SoftDeletes;

    /**
     * Everything that describes what was sold is written once, by the
     * conversion, and never through mass assignment afterwards.
     *
     * @var array<int, string>
     */
    protected $guarded = [
        'status',
        'payment_id',
        'customer_snapshot',
        'shipping_address_snapshot',
        'invoice_address_snapshot',
        'shipping_method_snapshot',
        'discounts_snapshot',
        'snapshot_fingerprint',
        'subtotal_excluding_vat',
        'subtotal_including_vat',
        'subtotal_vat_amount',
        'shipping_excluding_vat',
        'shipping_including_vat',
        'shipping_vat_amount',
        'discount_excluding_vat',
        'discount_including_vat',
        'discount_vat_amount',
        'total_excluding_vat',
        'total_including_vat',
        'total_vat_amount',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'shipped_at' => 'datetime',
            'customer_snapshot' => 'array',
            'shipping_address_snapshot' => 'array',
            'invoice_address_snapshot' => 'array',
            'shipping_method_snapshot' => 'array',
            'discounts_snapshot' => 'array',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Creation
    |--------------------------------------------------------------------------
    */

    /**
     * Create the order for a cart from a snapshot taken right now.
     */
    public static function createFromShoppingCart(ShoppingCart $cart): Order
    {
        return static::createFromSnapshot($cart->getPayableSnapshot(), $cart);
    }

    /**
     * Create (or return the existing) order for a cart from a snapshot — the
     * one payable froze onto the payment, or one taken on the spot. The order
     * is built from the snapshot alone; the cart is only stamped as converted
     * and linked. Idempotent on the cart id, so a webhook that fires twice
     * never creates a second order, and serialised per cart so two webhooks
     * at once cannot race past each other.
     *
     * Money has changed hands by now, so nothing here throws for a product
     * that sold out or a voucher that stopped qualifying in the meantime: the
     * order is created as paid for and {@see StockShortageDetected} /
     * {@see DiscountInvalidAtConversion} tell the host to follow up. Events
     * fire after the transaction commits; a listener that fails (a queue that
     * is down) must never roll back an order the customer already paid for.
     *
     * @param  array<string, mixed>  $snapshot
     */
    public static function createFromSnapshot(array $snapshot, ShoppingCart $cart, ?Model $payment = null): Order
    {
        /** @var array{order: Order, created: bool, duplicate: bool, shortages: array<int, array<string, mixed>>, invalid: array<int, array{code: string, reason: string}>} $result */
        $result = DB::transaction(function () use ($snapshot, $cart, $payment): array {
            /** @var class-string<Order> $orderModel */
            $orderModel = config('cart.models.order');

            // Serialise conversions per cart; the lock is a no-op on SQLite.
            $cart->newQuery()->withoutGlobalScopes()->whereKey($cart->getKey())->lockForUpdate()->first();

            // Without global scopes on purpose: an application can hide a
            // not-yet-finalised order behind a scope (a site scope, say), and
            // missing an existing one here would breach the unique
            // shopping_cart_id constraint on the second webhook. Carry a
            // customer back to the still-locked cart with a quiet write.
            if ($existing = $orderModel::query()->withoutGlobalScopes()->where('shopping_cart_id', $cart->id)->first()) {
                if ($existing->customer_id && ! $cart->customer_id) {
                    $cart->forceFill(['customer_id' => $existing->customer_id])->saveQuietly();
                }

                $duplicate = $payment !== null
                    && $existing->payment_id !== null
                    && $existing->payment_id !== (string) $payment->getKey();

                return ['order' => $existing, 'created' => false, 'duplicate' => $duplicate, 'shortages' => [], 'invalid' => []];
            }

            $lines = collect($snapshot['lines'] ?? []);

            if ($lines->where('type', CartItemType::Product->value)->isEmpty()) {
                throw EmptyCartException::make();
            }

            $shortages = static::stockShortagesIn($lines, $cart);
            $invalid = static::invalidDiscountsIn($snapshot, $cart);

            $customer = $cart->customer ?? $cart->prospect?->convertToCustomer();

            $order = new $orderModel;
            $order->forceFill([
                'shopping_cart_id' => $cart->id,
                'shopping_cart_display_id' => $snapshot['display_id'] ?? $cart->display_id,
                'payment_id' => $payment ? (string) $payment->getKey() : null,
                'customer_id' => $customer?->id,
                'user_id' => $snapshot['user_id'] ?? $cart->user_id,
                'shipping_address_id' => $snapshot['shipping_address_id'] ?? $cart->shipping_address_id,
                'invoice_address_id' => $snapshot['invoice_address_id'] ?? $snapshot['shipping_address_id'] ?? $cart->invoice_address_id ?? $cart->shipping_address_id,
                'shipping_method_id' => $snapshot['shipping_method_id'] ?? $cart->shipping_method_id,
                'note' => $snapshot['note'] ?? $cart->note,
                'currency' => static::currencyIn($snapshot, $lines),
                'status' => OrderStatus::Pending,
                'customer_snapshot' => $snapshot['customer'] ?? null,
                'shipping_address_snapshot' => $snapshot['shipping_address'] ?? null,
                'invoice_address_snapshot' => $snapshot['invoice_address'] ?? null,
                'shipping_method_snapshot' => $snapshot['shipping_method'] ?? null,
                'discounts_snapshot' => $snapshot['discounts'] ?? null,
                'snapshot_fingerprint' => $snapshot['fingerprint'] ?? null,
            ]);
            $order->save();

            foreach ($lines as $line) {
                $price = Price::fromGross((int) $line['unit_amount'], (float) $line['vat_percentage'], (string) $line['currency']);

                $order->items()->create([
                    'shopping_cart_item_id' => $line['id'] ?? null,
                    'purchasable_type' => $line['purchasable_type'] ?? null,
                    'purchasable_id' => $line['purchasable_id'] ?? null,
                    'description' => $line['description'],
                    'type' => CartItemType::from((string) $line['type']),
                    'quantity' => (int) $line['quantity'],
                    'price_excluding_vat' => $price->amountExcludingVat,
                    'price_including_vat' => $price->amountIncludingVat,
                    'vat_amount' => $price->vatAmount(),
                    'vat_percentage' => $price->vatPercentage,
                    'currency' => $price->currency,
                    'meta' => $line['meta'] ?? null,
                    'visible_in_cart' => (bool) ($line['visible_in_cart'] ?? true),
                ]);
            }

            $order->unsetRelation('items');
            $order->forceFill([
                'subtotal_excluding_vat' => $order->getSubtotalWithoutVat(),
                'subtotal_including_vat' => $order->getSubtotal(),
                'subtotal_vat_amount' => $order->getSubtotal() - $order->getSubtotalWithoutVat(),
                'shipping_excluding_vat' => $order->getShippingAmountWithoutVat(),
                'shipping_including_vat' => $order->getShippingAmount(),
                'shipping_vat_amount' => $order->getShippingVatAmount(),
                'discount_excluding_vat' => $order->getDiscountAmountWithoutVat(),
                'discount_including_vat' => $order->getDiscountAmount(),
                'discount_vat_amount' => $order->getDiscountVatAmount(),
                'total_excluding_vat' => $order->getTotalAmountWithoutVat(),
                'total_including_vat' => $order->getTotalAmount(),
                'total_vat_amount' => $order->getTotalVatAmount(),
            ])->saveQuietly();

            // The lines must add up to what the snapshot says was paid for;
            // a snapshot that does not reconcile is not one to build on.
            if (isset($snapshot['total_amount']) && (int) $snapshot['total_amount'] !== $order->total_including_vat) {
                throw PaymentAmountMismatchException::make((int) $snapshot['total_amount'], $order->total_including_vat);
            }

            $cart->forceFill([
                'confirmed_at' => $cart->confirmed_at ?? now(),
                'converted_at' => now(),
                'customer_id' => $customer ? $customer->id : $cart->customer_id,
            ])->saveQuietly();

            return ['order' => $order, 'created' => true, 'duplicate' => false, 'shortages' => $shortages, 'invalid' => $invalid];
        });

        $order = $result['order'];

        if ($result['created']) {
            event(new OrderCreated($order));

            if ($result['shortages'] !== []) {
                event(new StockShortageDetected($order, $result['shortages']));
            }

            foreach ($result['invalid'] as $invalid) {
                event(new DiscountInvalidAtConversion($order, $invalid['code'], $invalid['reason']));
            }
        } elseif ($result['duplicate'] && $payment) {
            event(new DuplicatePaymentDetected($order, $payment));
        }

        return $order;
    }

    /**
     * Product lines whose purchasable can no longer supply the paid quantity.
     *
     * @param  \Illuminate\Support\Collection<int, array<string, mixed>>  $lines
     * @return array<int, array<string, mixed>>
     */
    protected static function stockShortagesIn(\Illuminate\Support\Collection $lines, ShoppingCart $cart): array
    {
        if (! config('cart.stock.check_on_checkout', true)) {
            return [];
        }

        $shortages = [];

        foreach ($lines->where('type', CartItemType::Product->value) as $line) {
            $purchasable = static::resolvePurchasableOf($line['purchasable_type'] ?? null, $line['purchasable_id'] ?? null);

            if ($purchasable && ! $purchasable->isAvailableForPurchase((int) $line['quantity'], $cart)) {
                $shortages[] = [
                    'purchasable_type' => $line['purchasable_type'] ?? null,
                    'purchasable_id' => $line['purchasable_id'] ?? null,
                    'description' => (string) $line['description'],
                    'quantity' => (int) $line['quantity'],
                ];
            }
        }

        return $shortages;
    }

    /**
     * Vouchers on the snapshot that would be refused if applied now.
     *
     * @param  array<string, mixed>  $snapshot
     * @return array<int, array{code: string, reason: string}>
     */
    protected static function invalidDiscountsIn(array $snapshot, ShoppingCart $cart): array
    {
        $invalid = [];

        foreach ($snapshot['discounts'] ?? [] as $entry) {
            $code = (string) ($entry['code'] ?? '');
            $discount = $code !== '' ? config('cart.models.discount')::byCode($code) : null;

            if (! $discount) {
                continue;
            }

            try {
                $discount->assertAllowedOn($cart);
            } catch (DiscountException $e) {
                $invalid[] = ['code' => $code, 'reason' => $e->getMessage()];
            }
        }

        return $invalid;
    }

    protected static function resolvePurchasableOf(?string $type, int|string|null $id): ?Purchasable
    {
        if ($id === null) {
            return null;
        }

        $class = $type ? (Model::getActualClassNameForMorph($type)) : config('cart.models.product');

        if (! is_string($class) || ! class_exists($class)) {
            return null;
        }

        $purchasable = $class::find($id);

        return $purchasable instanceof Purchasable ? $purchasable : null;
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @param  \Illuminate\Support\Collection<int, array<string, mixed>>  $lines
     */
    protected static function currencyIn(array $snapshot, \Illuminate\Support\Collection $lines): string
    {
        $currency = $snapshot['currency'] ?? $lines->first()['currency'] ?? null;

        return is_string($currency) ? $currency : (string) config('cart.currency', 'EUR');
    }

    /*
    |--------------------------------------------------------------------------
    | Snapshot accessors
    |--------------------------------------------------------------------------
    */

    /**
     * @return array<string, mixed>
     */
    public function customerSnapshot(): array
    {
        return $this->customer_snapshot ?? [];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function shippingAddressSnapshot(): ?array
    {
        return $this->shipping_address_snapshot;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function invoiceAddressSnapshot(): ?array
    {
        return $this->invoice_address_snapshot;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function shippingMethodSnapshot(): ?array
    {
        return $this->shipping_method_snapshot;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function discountsSnapshot(): array
    {
        return $this->discounts_snapshot ?? [];
    }

    /*
    |--------------------------------------------------------------------------
    | Status
    |--------------------------------------------------------------------------
    */

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

    public function isRefunded(): bool
    {
        return $this->status === OrderStatus::Refunded;
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

    /**
     * Move the order to a status, quietly. Returns false when the order is
     * already there, and throws when {@see OrderStatus::canTransitionTo()}
     * rules the move out. {@see OrderStatusChanged} announces every move.
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

        event(new OrderStatusChanged($this, $from, $status));

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

    public function scopeRefunded(Builder $query): void
    {
        $query->where('status', OrderStatus::Refunded);
    }

    /*
    |--------------------------------------------------------------------------
    | Re-order
    |--------------------------------------------------------------------------
    */

    /**
     * Start a fresh cart holding this order's products again, at today's
     * prices and availability. Lines whose product has since vanished or sold
     * out are simply left off; the customer sees today's truth, not a replay
     * of the old receipt. The new cart becomes the session's cart.
     */
    public function toNewCart(): ShoppingCart
    {
        $cart = config('cart.models.shopping_cart')::completelyNew();

        $cart->withoutRecalculating(function () use ($cart): void {
            foreach ($this->items as $item) {
                if ($item->type !== CartItemType::Product) {
                    continue;
                }

                $purchasable = $item->resolvePurchasable();

                if (! $purchasable || ! $purchasable->isAvailableForPurchase($item->quantity, $cart)) {
                    continue;
                }

                $cart->add($purchasable, $item->quantity, $item->meta);
            }
        });

        return $cart;
    }

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

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

    public function payment(): BelongsTo
    {
        return $this->belongsTo(config('payable.models.payment'), 'payment_id');
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
