<?php

declare(strict_types=1);

namespace Marshmallow\Ecommerce\Cart\Models;

use Closure;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection as BaseCollection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Marshmallow\Addressable\Models\Address;
use Marshmallow\Addressable\Models\AddressType;
use Marshmallow\Ecommerce\Cart\Concerns\CalculatesTotals;
use Marshmallow\Ecommerce\Cart\Contracts\Purchasable;
use Marshmallow\Ecommerce\Cart\Enums\CartItemType;
use Marshmallow\Ecommerce\Cart\Events\CartConfirmed;
use Marshmallow\Ecommerce\Cart\Events\CartCreated;
use Marshmallow\Ecommerce\Cart\Events\CartReopened;
use Marshmallow\Ecommerce\Cart\Events\DiscountApplied;
use Marshmallow\Ecommerce\Cart\Events\DiscountRejected;
use Marshmallow\Ecommerce\Cart\Events\FeeChanged;
use Marshmallow\Ecommerce\Cart\Events\ItemAdded;
use Marshmallow\Ecommerce\Cart\Events\ItemPriceChanged;
use Marshmallow\Ecommerce\Cart\Events\ShippingCalculated;
use Marshmallow\Ecommerce\Cart\Events\ShippingMethodSelected;
use Marshmallow\Ecommerce\Cart\Exceptions\CartConvertedException;
use Marshmallow\Ecommerce\Cart\Exceptions\CartLockedException;
use Marshmallow\Ecommerce\Cart\Exceptions\CurrencyMismatchException;
use Marshmallow\Ecommerce\Cart\Exceptions\DiscountException;
use Marshmallow\Ecommerce\Cart\Exceptions\EmptyCartException;
use Marshmallow\Ecommerce\Cart\Exceptions\PaymentAmountMismatchException;
use Marshmallow\Ecommerce\Cart\Exceptions\PurchasableUnavailableException;
use Marshmallow\Ecommerce\Cart\Facades\Cart;
use Marshmallow\Ecommerce\Cart\Support\Price;
use Marshmallow\Payable\Models\PaymentType;
use Marshmallow\Payable\Traits\Payable;
use Marshmallow\Payable\Traits\PayableWithItems;

/**
 * A shopping cart, identified by a UUID kept in the session.
 *
 * The cart is the payable entity: payment happens against the cart, and only a
 * paid cart is converted into an immutable {@see Order}. Every money figure is
 * summed from the item snapshots by {@see CalculatesTotals}.
 *
 * Lifecycle: open (mutable) -> confirmed (frozen, at the payment provider) ->
 * converted (an order exists). {@see confirm()} runs every pre-payment check,
 * {@see reopen()} takes a cart back after a failed payment, and conversion
 * happens from the snapshot the payment was started with.
 *
 * @property string $id
 * @property int $display_id
 * @property string $guard_token
 * @property int|null $user_id
 * @property int|null $customer_id
 * @property int|null $prospect_id
 * @property int|null $shipping_address_id
 * @property int|null $invoice_address_id
 * @property int|null $shipping_method_id
 * @property string|null $note
 * @property Carbon|null $confirmed_at
 * @property Carbon|null $converted_at
 * @property Carbon|null $abandoned_at
 * @property Collection<int, ShoppingCartItem> $items
 * @property-read Prospect|null $prospect
 * @property-read Customer|null $customer
 * @property-read ShippingMethod|null $shippingMethod
 * @property-read Order|null $order
 */
class ShoppingCart extends Model
{
    use CalculatesTotals;
    use Payable, PayableWithItems {
        Payable::startPayment as protected startPaymentThroughPayable;
    }
    use SoftDeletes;

    public const SESSION_KEY = 'cart';

    public const SESSION_TOKEN_KEY = 'cart_token';

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * Columns the package manages itself. A host that mass-assigns request
     * input onto the cart must never be able to lock, convert or re-key it.
     *
     * @var array<int, string>
     */
    protected $guarded = ['guard_token', 'display_id', 'confirmed_at', 'converted_at', 'abandoned_at'];

    /**
     * Carts whose recalculation is deferred, keyed by cart id, mapping to
     * whether a change happened while deferred.
     *
     * @var array<string, bool>
     */
    protected static array $deferredRecalculations = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'confirmed_at' => 'datetime',
            'converted_at' => 'datetime',
            'abandoned_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (ShoppingCart $cart): void {
            if (! $cart->getKey()) {
                $cart->{$cart->getKeyName()} = (string) Str::orderedUuid();
            }

            // display_id is a global, sequential counter; ignore any host global
            // scope (e.g. a per-site scope) so it stays unique across the table.
            $cart->display_id ??= ((int) static::withoutGlobalScopes()->max('display_id')) + 1;
            $cart->guard_token ??= Str::random(64);

            $guard = Cart::getUserGuard();
            if (Auth::guard($guard)->check()) {
                $cart->fillFromUser(Auth::guard($guard)->user());
            }
        });

        // The prospect is minted after the insert, so a failed insert (a
        // display_id collision that is retried) leaves no orphan behind.
        static::created(function (ShoppingCart $cart): void {
            if (! $cart->prospect_id) {
                $prospect = config('cart.models.prospect')::create([]);
                $cart->prospect_id = $prospect->id;
                $cart->customer_id ??= $prospect->getCustomer()?->id;
                $cart->saveQuietly();
            }

            event(new CartCreated($cart));
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Adding items
    |--------------------------------------------------------------------------
    */

    /**
     * Add a purchasable to the cart at its own price.
     *
     * @param  array<string, mixed>|null  $meta
     */
    public function add(Purchasable $purchasable, int $quantity = 1, ?array $meta = null): ShoppingCartItem
    {
        return $this->addCustom(
            description: $purchasable->getPurchasableName(),
            price: $purchasable->getPurchasablePrice($quantity, $this->exists ? $this : null),
            type: CartItemType::Product,
            quantity: $quantity,
            purchasable: $purchasable,
            meta: $meta,
            customPrice: false,
        );
    }

    /**
     * Add an arbitrary line to the cart, snapshotting the given price.
     *
     * A line added here carries a price the caller chose (an option surcharge,
     * a fee), so quantity changes never re-ask the purchasable for it; a line
     * added through {@see add()} follows the purchasable's own (possibly
     * tiered) pricing instead, and is checked against the purchasable's
     * availability for the line's total quantity.
     *
     * @param  array<string, mixed>|null  $meta
     */
    public function addCustom(
        string $description,
        Price $price,
        CartItemType $type,
        bool $visibleInCart = true,
        int $quantity = 1,
        ?Purchasable $purchasable = null,
        ?array $meta = null,
        bool $combine = true,
        bool $customPrice = true,
    ): ShoppingCartItem {
        $this->assertOpen();

        if (! $this->exists) {
            $this->persistAsSessionCart();
        }

        $this->assertCurrencyMatches($price->currency);

        $itemModel = config('cart.models.shopping_cart_item');
        $purchasableType = $purchasable ? $itemModel::morphTypeOf($purchasable) : null;
        $signature = $itemModel::signatureFor($purchasableType, $purchasable?->getPurchasableKey(), $type, $meta);

        /** @var ShoppingCartItem|null $existing */
        $existing = $combine
            ? $this->items()->where('signature', $signature)->first()
            : null;

        if ($purchasable && ! $customPrice) {
            $this->guardAvailability($purchasable, ($existing ? $existing->quantity : 0) + $quantity);
        }

        if ($existing) {
            $existing->setRelation('cart', $this);
            $existing->increaseQuantity($quantity);

            return $existing;
        }

        $item = new $itemModel([
            'shopping_cart_id' => $this->id,
            'purchasable_type' => $purchasableType,
            'purchasable_id' => $purchasable?->getPurchasableKey(),
            'description' => $description,
            'type' => $type,
            'quantity' => $quantity,
            'meta' => $meta,
            'visible_in_cart' => $visibleInCart,
            'custom_price' => $customPrice,
            ...$itemModel::priceAttributes($price),
        ]);
        $item->setRelation('cart', $this);
        $item->save();

        event(new ItemAdded($this, $item));

        return $item;
    }

    /**
     * Remove a line from the cart.
     */
    public function remove(ShoppingCartItem|int $item): void
    {
        $this->line($item)->delete();
    }

    /**
     * Set the quantity of a line; zero removes it.
     */
    public function setQuantity(ShoppingCartItem|int $item, int $quantity): void
    {
        $this->line($item)->setQuantity($quantity);
    }

    /**
     * A line of this cart, by model or key; anything else is not found.
     */
    protected function line(ShoppingCartItem|int $item): ShoppingCartItem
    {
        $line = $item instanceof ShoppingCartItem ? $item : $this->items()->find($item);

        if (! $line instanceof ShoppingCartItem || $line->shopping_cart_id !== $this->id) {
            throw (new ModelNotFoundException)->setModel(config('cart.models.shopping_cart_item'), [$item instanceof ShoppingCartItem ? $item->getKey() : $item]);
        }

        $line->setRelation('cart', $this);

        return $line;
    }

    /**
     * Empty the cart: every product and fee line goes, and the derived
     * shipping and discount lines follow in one recalculation.
     */
    public function clear(): void
    {
        $this->assertOpen();

        $this->withoutRecalculating(function (): void {
            $this->loadedItems()
                ->filter(fn (ShoppingCartItem $item): bool => $item->isProduct() || $item->isFee())
                ->each(function (ShoppingCartItem $item): void {
                    $item->setRelation('cart', $this);
                    $item->delete();
                });
        });
    }

    /**
     * Totals are plain sums of cents, so every line has to share one currency.
     */
    protected function assertCurrencyMatches(string $currency): void
    {
        $other = $this->items()->where('currency', '!=', $currency)->value('currency');

        if (is_string($other)) {
            throw CurrencyMismatchException::make($other, $currency);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Lifecycle: open, confirmed, converted
    |--------------------------------------------------------------------------
    */

    /**
     * Whether the cart may still be changed: a cart confirmed for payment is
     * frozen until it is reopened.
     */
    public function isOpen(): bool
    {
        return $this->confirmed_at === null;
    }

    public function isConfirmed(): bool
    {
        return $this->confirmed_at !== null;
    }

    public function isConverted(): bool
    {
        return $this->converted_at !== null;
    }

    /**
     * Refuse any change to a cart that has been confirmed for payment.
     */
    public function assertOpen(): void
    {
        if ($this->isConverted()) {
            throw CartConvertedException::make();
        }

        if (! $this->isOpen()) {
            throw CartLockedException::make();
        }
    }

    /**
     * Freeze the cart for payment. Every check that should stop a customer
     * before money changes hands runs here: the cart must hold products,
     * every product must be available in its quantity, and every applied
     * voucher must still be allowed (usage limits, once-per-customer with the
     * e-mail known by now). A failure throws and leaves the cart open.
     * Confirming an already confirmed cart is a no-op.
     */
    public function confirm(): static
    {
        if ($this->isConverted()) {
            throw CartConvertedException::make();
        }

        if ($this->isConfirmed()) {
            return $this;
        }

        $this->unsetRelation('items');

        if ($this->productItems()->isEmpty()) {
            throw EmptyCartException::make();
        }

        foreach ($this->productItems() as $item) {
            if ($item->custom_price || ! ($purchasable = $item->resolvePurchasable())) {
                continue;
            }

            $this->guardAvailability($purchasable, $item->quantity, 'check_on_checkout');
        }

        foreach ($this->discounts() as $discount) {
            $discount->assertAllowedOn($this);
        }

        $this->forceFill(['confirmed_at' => now()])->saveQuietly();

        event(new CartConfirmed($this));

        return $this;
    }

    /**
     * Take a confirmed cart back, e.g. after a failed or canceled payment. A
     * payment that still settles later creates its order from the snapshot it
     * was started with, never from the reopened cart.
     */
    public function reopen(): static
    {
        if ($this->isConverted()) {
            throw CartConvertedException::make();
        }

        if ($this->isOpen()) {
            return $this;
        }

        $this->forceFill(['confirmed_at' => null])->saveQuietly();

        event(new CartReopened($this));

        return $this;
    }

    /*
    |--------------------------------------------------------------------------
    | Recalculation
    |--------------------------------------------------------------------------
    */

    /**
     * React to a change in the cart's contents. Shipping and discount lines are
     * derived, so a change to one of them must not trigger another pass — that
     * is what keeps this from recursing.
     */
    public function shoppingCartContentChanged(ShoppingCartItem $item): void
    {
        if ($item->isShippingCost() || $item->isDiscount() || $item->isFee()) {
            return;
        }

        if (array_key_exists($this->id, static::$deferredRecalculations)) {
            static::$deferredRecalculations[$this->id] = true;

            return;
        }

        $this->recalculate();
    }

    /**
     * Run the derived-line pass once: shipping, then discounts.
     */
    public function recalculate(): void
    {
        // A change to the contents is activity: a cart flagged as abandoned
        // is live again, and housekeeping may flag it afresh later.
        if ($this->abandoned_at) {
            $this->forceFill(['abandoned_at' => null])->saveQuietly();
        }

        $this->unsetRelation('items');
        $this->calculateShippingCost();
        $this->recalculateDiscount();
    }

    /**
     * Run a batch of changes with a single recalculation at the end instead
     * of one per line, e.g. when merging carts or refreshing every price.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public function withoutRecalculating(Closure $callback): mixed
    {
        $nested = array_key_exists($this->id, static::$deferredRecalculations);

        if (! $nested) {
            static::$deferredRecalculations[$this->id] = false;
        }

        try {
            return $callback();
        } finally {
            if (! $nested) {
                $changed = static::$deferredRecalculations[$this->id];
                unset(static::$deferredRecalculations[$this->id]);

                if ($changed) {
                    $this->recalculate();
                }
            }
        }
    }

    public function getShippingItem(): ?ShoppingCartItem
    {
        return $this->shippingItems()->first();
    }

    protected function calculateShippingCost(): void
    {
        $this->getShippingItem()?->setRelation('cart', $this)->delete();
        $this->unsetRelation('items');

        $method = $this->productItems()->isEmpty() ? null : $this->resolveShippingMethod();

        if (! $method) {
            $this->forceFill(['shipping_method_id' => null])->saveQuietly();
            event(new ShippingCalculated($this, null, Price::zero()));

            return;
        }

        $this->forceFill(['shipping_method_id' => $method->id])->saveQuietly();

        // Priced against this cart, not at face value: a method with a
        // free-from-amount has to land at zero here as well, or the basket
        // quotes a shipping cost the checkout then drops.
        $price = $method->priceForCart($this);
        $this->addCustom($method->name, $price, CartItemType::Shipping, visibleInCart: false);

        event(new ShippingCalculated($this, $method, $price));
    }

    /**
     * The method to ship this cart with: the one the customer picked, for as
     * long as it is still active and its conditions still fit the cart, and
     * otherwise the default the conditions select. Without this a change to
     * the contents would silently swap the customer's choice for the default.
     */
    protected function resolveShippingMethod(): ?ShippingMethod
    {
        $methodModel = config('cart.models.shipping_method');

        if ($this->shipping_method_id && ! $this->hasExcludedShipping()) {
            /** @var ShippingMethod|null $chosen */
            $chosen = $methodModel::currentlyActive()
                ->with('conditions')
                ->whereKey($this->shipping_method_id)
                ->first();

            if ($chosen && $chosen->appliesToSubtotal($this->getSubtotal())) {
                return $chosen;
            }
        }

        return $methodModel::calculateFromCart($this);
    }

    protected function recalculateDiscount(): void
    {
        $codes = $this->discountItems()->pluck('description')->unique()->values();

        if ($codes->isEmpty()) {
            return;
        }

        $this->discountItems()->each(fn (ShoppingCartItem $item) => $item->setRelation('cart', $this)->delete());
        $this->unsetRelation('items');

        $discounts = config('cart.models.discount')::byCodes($codes);

        // Reapply in the order they were added, so cumulative percentages keep
        // their meaning. A code that no longer qualifies simply drops off.
        foreach ($codes as $code) {
            if ($discount = $discounts->get($code)) {
                try {
                    $this->applyDiscount($discount);
                } catch (DiscountException) {
                    // Dropped: DiscountRejected has already been announced.
                }
            }
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Discounts
    |--------------------------------------------------------------------------
    */

    /**
     * Apply a discount to the cart, or reject it with a reason.
     *
     * Codes stack only when every code on the cart — the ones already applied
     * and the new one — is marked combinable; otherwise the new code replaces
     * whatever was there. The same code never applies twice. The whole step is
     * one transaction, so a rejected replacement leaves the existing codes
     * untouched. A discount books one line per VAT rate it spans, so the VAT
     * on the discount mirrors the VAT on what it discounts.
     */
    public function applyDiscount(Discount $discount): void
    {
        $this->assertOpen();

        try {
            DB::transaction(function () use ($discount): void {
                if ($this->discountItems()->pluck('description')->contains($discount->discount_code)) {
                    throw new DiscountException(__('This voucher is already applied to your shopping cart.'));
                }

                if (! $this->canCombineWith($discount)) {
                    $this->removeDiscount();
                }

                $discount->assertAllowedOn($this);
                $prices = $discount->calculateForCart($this);

                // Never signature-combine: two codes share a null purchasable and
                // would otherwise collapse into one double-quantity line.
                foreach ($prices as $price) {
                    $this->addCustom($discount->discount_code, $price, CartItemType::Discount, visibleInCart: false, combine: false);
                }

                $this->unsetRelation('items');

                event(new DiscountApplied(
                    $this,
                    $discount,
                    (int) $prices->sum(fn (Price $price): int => $price->amountIncludingVat),
                    $prices,
                ));
            });
        } catch (DiscountException $e) {
            event(new DiscountRejected($this, $discount, $e->getMessage()));

            throw $e;
        }
    }

    /**
     * Remove one discount by its code, or every discount when none is given.
     */
    public function removeDiscount(?string $code = null): void
    {
        $this->assertOpen();

        $this->discountItems()
            ->when($code !== null, fn ($items) => $items->where('description', $code))
            ->each(fn (ShoppingCartItem $item) => $item->setRelation('cart', $this)->delete());
        $this->unsetRelation('items');
    }

    /**
     * The discounts currently applied, in the order they were added.
     *
     * @return BaseCollection<int, Discount>
     */
    public function discounts(): BaseCollection
    {
        $codes = $this->discountItems()->pluck('description')->unique()->values();

        if ($codes->isEmpty()) {
            return new BaseCollection;
        }

        $byCode = config('cart.models.discount')::byCodes($codes);

        return $codes->map(fn (string $code) => $byCode->get($code))->filter()->values();
    }

    /**
     * Whether the given discount may live alongside the codes already applied.
     */
    protected function canCombineWith(Discount $discount): bool
    {
        $applied = $this->discountItems()->pluck('description')->unique();

        if ($applied->isEmpty()) {
            return true;
        }

        if (! $discount->is_combinable) {
            return false;
        }

        $byCode = config('cart.models.discount')::byCodes($applied);

        return $applied->every(
            fn (string $code): bool => (bool) $byCode->get($code)?->is_combinable,
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Shipping & fees
    |--------------------------------------------------------------------------
    */

    /**
     * Apply the shipping method the customer picked, replacing whatever shipping
     * line the cart held. Passing null clears shipping entirely (e.g. pickup).
     * The method prices itself against the current cart, so a "free over X"
     * method lands at zero once the order is large enough.
     */
    public function selectShippingMethod(?ShippingMethod $method): void
    {
        $this->assertOpen();

        $this->getShippingItem()?->setRelation('cart', $this)->delete();
        $this->unsetRelation('items');

        if ($method) {
            $this->forceFill(['shipping_method_id' => $method->id])->saveQuietly();
            $price = $method->priceForCart($this);
            $this->addCustom($method->name, $price, CartItemType::Shipping, visibleInCart: false);
            $this->unsetRelation('items');
        } else {
            $this->forceFill(['shipping_method_id' => null])->saveQuietly();
            $price = Price::zero();
        }

        event(new ShippingMethodSelected($this, $method, $price));

        // Shipping lines are derived, so changing one does not trigger the
        // content-changed pass; a free-shipping code still has to follow the
        // new shipping amount, or the discount would keep the old cost.
        $this->recalculateDiscount();
    }

    /**
     * Set (or clear) a single fee line, such as a payment surcharge. Passing
     * null or a zero price removes it, so switching to a method without a
     * surcharge leaves no stray line behind.
     */
    public function setFee(string $description, ?Price $price): void
    {
        $this->assertOpen();

        $this->feeItems()->each(fn (ShoppingCartItem $item) => $item->setRelation('cart', $this)->delete());
        $this->unsetRelation('items');

        if ($price && ! $price->isZero()) {
            $this->addCustom($description, $price, CartItemType::Fee, visibleInCart: true);
            $this->unsetRelation('items');
        }

        event(new FeeChanged($this, $description, $price && ! $price->isZero() ? $price : null));
    }

    /**
     * A hook for a host to rule shipping out for a cart entirely (a
     * download-only order, say). Override it in a subclass; the default
     * never excludes shipping.
     */
    public function hasExcludedShipping(): bool
    {
        return false;
    }

    /*
    |--------------------------------------------------------------------------
    | Users, customers & addresses
    |--------------------------------------------------------------------------
    */

    public function fillFromUser(Model $user): void
    {
        $user = $user->fresh();
        $this->user_id = $user->getKey();
        $this->customer_id = $this->customerKeyFromUser($user);

        if (! method_exists($user, 'getDefaultAddress')) {
            return;
        }

        $shipping = $this->defaultAddressOf($user, AddressType::SHIPPING);
        if ($shipping && ! $this->hasShippingAddress()) {
            $this->shipping_address_id = $shipping->id;
        }

        $invoice = $this->defaultAddressOf($user, AddressType::INVOICE);
        if ($invoice && ! $this->hasInvoiceAddress()) {
            $this->invoice_address_id = $invoice->id;
        }
    }

    /**
     * The key of the customer a user maps to, if any. A host User need not have
     * a customer relation at all; when it does not, the cart simply carries no
     * customer and resolves one from its prospect at checkout instead. Reading
     * the relation only when it exists keeps a strict-mode host from throwing a
     * MissingAttributeException on login.
     */
    protected function customerKeyFromUser(Model $user): int|string|null
    {
        if (! method_exists($user, 'customer')) {
            return null;
        }

        // The relation is provided by the host User; the guard above proves it
        // exists, so reading it will not throw under strict attribute access.
        $customer = $user->customer; // @phpstan-ignore property.notFound

        return $customer instanceof Model ? $customer->getKey() : null;
    }

    /**
     * A user's default address of a given type, or null when the address book
     * (or the address type itself) is not set up. A shop that has not defined
     * its address types must not crash the login flow.
     */
    protected function defaultAddressOf(Model $user, string $type): ?Address
    {
        try {
            // The host User provides getDefaultAddress() via the Addressable
            // trait; the caller has already checked it exists.
            $address = $user->getDefaultAddress($type); // @phpstan-ignore method.notFound

            return $address instanceof Address ? $address : null;
        } catch (ModelNotFoundException) {
            return null;
        }
    }

    public function connectUser(Model $user): void
    {
        $this->fillFromUser($user);
        $this->save();
    }

    public function disconnectUser(): void
    {
        $this->forceFill([
            'user_id' => null,
            'customer_id' => null,
        ])->save();
    }

    public function addCustomerIfExists(): void
    {
        if ($this->customer_id) {
            return;
        }

        $this->customer_id = $this->prospect?->getCustomer()?->id;
        $this->saveQuietly();
    }

    public function hasShippingAddress(): bool
    {
        return $this->shipping_address_id !== null;
    }

    public function hasInvoiceAddress(): bool
    {
        return $this->invoice_address_id !== null;
    }

    public function connectShippingAddress(Address $address): void
    {
        $this->assertOpen();
        $this->update(['shipping_address_id' => $address->id]);
    }

    public function connectInvoiceAddress(Address $address): void
    {
        $this->assertOpen();
        $this->update(['invoice_address_id' => $address->id]);
    }

    public function getCustomerOrProspect(): Customer|Prospect|null
    {
        return $this->customer ?? $this->prospect;
    }

    /*
    |--------------------------------------------------------------------------
    | Conversion
    |--------------------------------------------------------------------------
    */

    /**
     * Turn the cart into an order, from a snapshot taken right now. When the
     * settled payment amount is passed, it must equal the cart's total to the
     * cent — a mismatch means the cart changed after the payment started (or
     * the payment settled short), and an order must never be created for a
     * different amount than was paid. A payment started through payable
     * converts from the snapshot frozen on the payment instead; see
     * {@see Order::createFromSnapshot()}.
     */
    public function convertToOrder(?int $expectedTotalAmount = null): Order
    {
        $this->unsetRelation('items');

        if ($expectedTotalAmount !== null && $expectedTotalAmount !== $this->getTotalAmount()) {
            throw PaymentAmountMismatchException::make($expectedTotalAmount, $this->getTotalAmount());
        }

        return config('cart.models.order')::createFromSnapshot($this->getPayableSnapshot(), $this);
    }

    /**
     * Re-ask every purchasable-priced line for its current price and reprice
     * the lines that moved, firing {@see ItemPriceChanged} per line. Lines with
     * a caller-chosen price (options, fees) are left alone. Returns the lines
     * that changed, so a storefront can tell the customer what moved.
     *
     * @return Collection<int, ShoppingCartItem>
     */
    public function refreshPrices(): Collection
    {
        $this->assertOpen();

        return $this->withoutRecalculating(function (): Collection {
            $changed = [];

            foreach ($this->productItems() as $item) {
                if ($item->custom_price) {
                    continue;
                }

                $purchasable = $item->resolvePurchasable();

                if (! $purchasable) {
                    continue;
                }

                $current = $purchasable->getPurchasablePrice($item->quantity, $this);

                if ($current->equals($item->price())) {
                    continue;
                }

                $from = $item->price();
                $item->setRelation('cart', $this);
                $item->applyPrice($current);
                $changed[] = $item;

                event(new ItemPriceChanged($this, $item, $from, $current));
            }

            return new Collection($changed);
        });
    }

    /**
     * Fold another cart's product lines into this one, combining matching lines
     * and copying over a note or addresses this cart is still missing. The
     * source cart is soft-deleted with its lines.
     */
    public function mergeFrom(ShoppingCart $source): void
    {
        $this->assertOpen();

        $this->withoutRecalculating(function () use ($source): void {
            /** @var iterable<ShoppingCartItem> $productItems */
            $productItems = $source->items()->where('type', CartItemType::Product)->get();

            foreach ($productItems as $item) {
                $this->absorbItem($item);
            }
        });

        $this->note ??= $source->note;
        $this->shipping_address_id ??= $source->shipping_address_id;
        $this->invoice_address_id ??= $source->invoice_address_id;
        $this->save();

        $source->delete();
    }

    protected function absorbItem(ShoppingCartItem $item): void
    {
        $purchasable = $item->resolvePurchasable();

        /** @var ShoppingCartItem|null $existing */
        $existing = $this->items()->where('signature', $item->signature)->first();
        $total = ($existing ? $existing->quantity : 0) + $item->quantity;

        if ($purchasable && ! $purchasable->isAvailableForPurchase($total, $this)) {
            return;
        }

        if ($existing) {
            $existing->setRelation('cart', $this);
            $existing->increaseQuantity($item->quantity);

            return;
        }

        $copy = $item->replicate(['shopping_cart_id']);
        $copy->shopping_cart_id = $this->id;
        $copy->setRelation('cart', $this);
        $copy->save();
    }

    /*
    |--------------------------------------------------------------------------
    | Payable contract
    |--------------------------------------------------------------------------
    */

    /**
     * Start a payment for this cart. The cart is confirmed first, so it is
     * frozen and validated before the payment provider is contacted; the
     * snapshot payable stores on the payment is the one the order will be
     * built from.
     */
    public function startPayment(
        PaymentType $paymentType,
        $testPayment = null,
        $apiKey = null,
        ?callable $extraPaymentDataCallback = null,
        ?callable $extraPaymentModifier = null,
        bool $is_recurring = false,
        bool $is_custom = false,
    ) {
        $this->confirm();

        return $this->startPaymentThroughPayable(
            $paymentType,
            $testPayment,
            $apiKey,
            $extraPaymentDataCallback,
            $extraPaymentModifier,
            $is_recurring,
            $is_custom,
        );
    }

    public function paymentAllowed()
    {
        return ! $this->isConverted();
    }

    public function getPayableDescription(): string
    {
        return __('Order').' #'.$this->display_id;
    }

    /**
     * A frozen, self-contained description of what a payment for this cart
     * covers: every line, the parties, the addresses, the shipping method and
     * the totals. Payable stores it on the payment the moment the payment
     * starts, and the order is built from it — so the order records what was
     * paid for, whatever happens to the cart, the addresses or the products
     * afterwards.
     *
     * @return array<string, mixed>
     */
    public function getPayableSnapshot(): array
    {
        $this->unsetRelation('items');
        $party = $this->getCustomerOrProspect();

        $snapshot = [
            'version' => 2,
            'cart_id' => $this->id,
            'display_id' => $this->display_id,
            'currency' => $this->currencyOfLines(),
            'note' => $this->note,
            'user_id' => $this->user_id,
            'customer_id' => $this->customer_id,
            'prospect_id' => $this->prospect_id,
            'shipping_method_id' => $this->shipping_method_id,
            'shipping_address_id' => $this->shipping_address_id,
            'invoice_address_id' => $this->invoice_address_id,
            'customer' => $party ? [
                'type' => $party::class,
                'id' => $party->getKey(),
                'first_name' => $party->first_name,
                'last_name' => $party->last_name,
                'company_name' => $party->company_name,
                'email' => $party->email,
                'phone_number' => $party->phone_number,
                'country_id' => $party->country_id,
            ] : null,
            'shipping_address' => $this->addressSnapshot($this->shippingAddress),
            'invoice_address' => $this->addressSnapshot($this->invoiceAddress ?? $this->shippingAddress),
            'shipping_method' => $this->shippingMethod ? [
                'id' => $this->shippingMethod->id,
                'name' => $this->shippingMethod->name,
                'type' => $this->shippingMethod->type,
                'price_including_vat' => $this->shippingMethod->price_including_vat,
                'vat_percentage' => (float) $this->shippingMethod->vat_percentage,
                'currency' => $this->shippingMethod->currency,
                'free_from_amount' => $this->shippingMethod->free_from_amount,
            ] : null,
            'discounts' => $this->discountItems()
                ->groupBy('description')
                ->map(fn (Collection $lines, string $code): array => [
                    'code' => $code,
                    'amount' => (int) $lines->sum(fn (ShoppingCartItem $line): int => $line->getTotalAmount()),
                ])
                ->values()
                ->all(),
            'lines' => $this->loadedItems()->map(fn (ShoppingCartItem $item): array => [
                'id' => $item->id,
                'purchasable_type' => $item->purchasable_type,
                'purchasable_id' => $item->purchasable_id,
                'description' => $item->description,
                'type' => $item->type->value,
                'quantity' => $item->quantity,
                'unit_amount' => $item->getUnitAmount(),
                'unit_amount_excluding_vat' => $item->getUnitAmountWithoutVat(),
                'unit_vat_amount' => $item->getUnitVatAmount(),
                'total_amount' => $item->getTotalAmount(),
                'total_amount_excluding_vat' => $item->getTotalAmountWithoutVat(),
                'vat_percentage' => (float) $item->vat_percentage,
                'currency' => $item->currency,
                'meta' => $item->meta,
                'visible_in_cart' => $item->visible_in_cart,
                'custom_price' => $item->custom_price,
            ])->values()->all(),
            'totals' => [
                'subtotal' => $this->getSubtotal(),
                'subtotal_excluding_vat' => $this->getSubtotalWithoutVat(),
                'shipping' => $this->getShippingAmount(),
                'shipping_excluding_vat' => $this->getShippingAmountWithoutVat(),
                'discount' => $this->getDiscountAmount(),
                'discount_excluding_vat' => $this->getDiscountAmountWithoutVat(),
                'fee' => $this->getFeeAmount(),
                'fee_excluding_vat' => $this->getFeeAmountWithoutVat(),
                'total' => $this->getTotalAmount(),
                'total_excluding_vat' => $this->getTotalAmountWithoutVat(),
                'total_vat' => $this->getTotalVatAmount(),
            ],
            'total_amount' => $this->getTotalAmount(),
            'total_vat_amount' => $this->getTotalVatAmount(),
        ];

        $snapshot['fingerprint'] = static::fingerprintOf($snapshot);
        $snapshot['created_at'] = now()->toIso8601String();

        return $snapshot;
    }

    /**
     * A hash over everything in a snapshot that determines what is paid for.
     *
     * @param  array<string, mixed>  $snapshot
     */
    public static function fingerprintOf(array $snapshot): string
    {
        unset($snapshot['fingerprint'], $snapshot['created_at']);

        return hash('sha256', (string) json_encode($snapshot));
    }

    /**
     * Every column of an address, plus the country's name and code, so the
     * snapshot reads without the address book or the country table.
     *
     * @return array<string, mixed>|null
     */
    protected function addressSnapshot(?Model $address): ?array
    {
        if (! $address) {
            return null;
        }

        $country = $address->getAttribute('country');

        return [
            'id' => $address->getKey(),
            ...$address->only([
                'name', 'first_name', 'last_name',
                'address_line_1', 'address_line_2', 'address_line_3', 'address_line_4',
                'postal_code', 'city', 'state', 'country_id',
            ]),
            'country_name' => $country instanceof Model ? $country->getAttribute('name') : null,
            'country_code' => $country instanceof Model ? $country->getAttribute('alpha2') : null,
        ];
    }

    protected function currencyOfLines(): string
    {
        $currency = $this->loadedItems()->pluck('currency')->first();

        return is_string($currency) ? $currency : (string) config('cart.currency', 'EUR');
    }

    public function getCustomer(): ?Model
    {
        return $this->getCustomerOrProspect();
    }

    public function getCustomerName(): ?string
    {
        return $this->getCustomerOrProspect()?->getFullName() ?: null;
    }

    public function getCustomerEmail(): ?string
    {
        return $this->getCustomerOrProspect()?->email;
    }

    public function getCustomerPhonenumber(): ?string
    {
        return $this->getCustomerOrProspect()?->phone_number;
    }

    /*
    |--------------------------------------------------------------------------
    | Session lifecycle
    |--------------------------------------------------------------------------
    */

    /**
     * The cart the session points at, or null when it points at none. A cart
     * the session may not act on is replaced by a fresh one: a cart from
     * before guard tokens existed, one whose token the session does not hold,
     * an orphan without any owner, or one that already became an order.
     */
    public static function getBySession(): ?ShoppingCart
    {
        $id = session()->get(self::SESSION_KEY);

        if (! $id) {
            return null;
        }

        $cart = static::query()->with(['prospect', 'customer'])->find($id);

        if (! $cart) {
            return null;
        }

        $owned = $cart->user_id !== null || $cart->customer_id !== null || $cart->prospect !== null;

        if (! $cart->guard_token || ! $cart->authorized() || ! $owned || $cart->isConverted()) {
            return static::completelyNew();
        }

        return $cart;
    }

    public static function completelyNew(): ShoppingCart
    {
        return static::query()->getModel()->newInstance()->persistAsSessionCart();
    }

    /**
     * Save this (new) cart and make it the session's cart. A display_id
     * collision under concurrency is retried with fresh identifiers.
     */
    public function persistAsSessionCart(): static
    {
        for ($attempt = 0; ; $attempt++) {
            try {
                $this->save();

                break;
            } catch (UniqueConstraintViolationException $e) {
                if ($attempt >= 3) {
                    throw $e;
                }

                unset($this->{$this->getKeyName()}, $this->display_id, $this->guard_token);
            }
        }

        session()->put(self::SESSION_KEY, $this->id);
        session()->put(self::SESSION_TOKEN_KEY, $this->guard_token);

        return $this;
    }

    public static function newWithSameProspect(ShoppingCart $cart): ShoppingCart
    {
        $new = static::completelyNew();
        $new->update(['prospect_id' => $cart->prospect_id]);

        return $new;
    }

    /**
     * The user's most recent cart that has not been paid for or converted.
     */
    public static function latestOpenForUser(Model $user): ?ShoppingCart
    {
        return static::query()
            ->where('user_id', $user->getKey())
            ->whereNull('confirmed_at')
            ->whereNull('converted_at')
            ->latest()
            ->first();
    }

    /**
     * Whether the current session is allowed to read this cart. The random
     * guard token was stored in the session when the cart was created; only a
     * session holding it may act on the cart.
     */
    public function authorized(): bool
    {
        $token = session()->get(self::SESSION_TOKEN_KEY);

        return is_string($this->guard_token)
            && $this->guard_token !== ''
            && is_string($token)
            && hash_equals($this->guard_token, $token);
    }

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    public function items(): HasMany
    {
        return $this->hasMany(config('cart.models.shopping_cart_item'), 'shopping_cart_id');
    }

    public function order(): HasOne
    {
        return $this->hasOne(config('cart.models.order'), 'shopping_cart_id');
    }

    public function prospect(): BelongsTo
    {
        return $this->belongsTo(config('cart.models.prospect'));
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

    public function shippingAddress(): BelongsTo
    {
        return $this->belongsTo(config('cart.models.address'), 'shipping_address_id');
    }

    public function invoiceAddress(): BelongsTo
    {
        return $this->belongsTo(config('cart.models.address'), 'invoice_address_id');
    }

    private function guardAvailability(Purchasable $purchasable, int $quantity, string $configKey = 'check_on_add'): void
    {
        if (! config("cart.stock.{$configKey}", true)) {
            return;
        }

        if (! $purchasable->isAvailableForPurchase($quantity, $this->exists ? $this : null)) {
            throw PurchasableUnavailableException::for($purchasable, $quantity);
        }
    }
}
