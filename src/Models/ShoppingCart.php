<?php

declare(strict_types=1);

namespace Marshmallow\Ecommerce\Cart\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Marshmallow\Addressable\Models\Address;
use Marshmallow\Addressable\Models\AddressType;
use Marshmallow\Ecommerce\Cart\Concerns\CalculatesTotals;
use Marshmallow\Ecommerce\Cart\Contracts\Purchasable;
use Marshmallow\Ecommerce\Cart\Enums\CartItemType;
use Marshmallow\Ecommerce\Cart\Events\CartCreated;
use Marshmallow\Ecommerce\Cart\Events\DiscountApplied;
use Marshmallow\Ecommerce\Cart\Events\DiscountRejected;
use Marshmallow\Ecommerce\Cart\Events\ItemAdded;
use Marshmallow\Ecommerce\Cart\Events\ItemPriceChanged;
use Marshmallow\Ecommerce\Cart\Events\ShippingCalculated;
use Marshmallow\Ecommerce\Cart\Exceptions\CartLockedException;
use Marshmallow\Ecommerce\Cart\Exceptions\DiscountException;
use Marshmallow\Ecommerce\Cart\Exceptions\PaymentAmountMismatchException;
use Marshmallow\Ecommerce\Cart\Exceptions\PurchasableUnavailableException;
use Marshmallow\Ecommerce\Cart\Facades\Cart;
use Marshmallow\Ecommerce\Cart\Support\Price;
use Marshmallow\Payable\Traits\Payable;
use Marshmallow\Payable\Traits\PayableWithItems;

/**
 * A shopping cart, identified by a UUID kept in the session.
 *
 * The cart is the payable entity: payment happens against the cart, and only a
 * paid cart is converted into an immutable {@see Order}. Every money figure is
 * summed from the item snapshots by {@see CalculatesTotals}.
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
 * @property Collection<int, ShoppingCartItem> $items
 * @property-read Prospect|null $prospect
 * @property-read Customer|null $customer
 * @property-read ShippingMethod|null $shippingMethod
 */
class ShoppingCart extends Model
{
    use CalculatesTotals;
    use Payable;
    use PayableWithItems;
    use SoftDeletes;

    public const SESSION_KEY = 'cart';

    public const SESSION_TOKEN_KEY = 'cart_token';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'confirmed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (ShoppingCart $cart): void {
            if (! $cart->getKey()) {
                $cart->{$cart->getKeyName()} = (string) Str::uuid();
            }

            // display_id is a global, sequential counter; ignore any host global
            // scope (e.g. a per-site scope) so it stays unique across the table.
            $cart->display_id ??= ((int) static::withoutGlobalScopes()->max('display_id')) + 1;
            $cart->guard_token ??= Str::random(64);

            $guard = Cart::getUserGuard();
            if (Auth::guard($guard)->check()) {
                $cart->fillFromUser(Auth::guard($guard)->user());
            }

            if (! $cart->prospect_id) {
                $prospect = config('cart.models.prospect')::create([]);
                $cart->prospect_id = $prospect->id;
                $cart->customer_id ??= $prospect->getCustomer()?->id;
            }
        });

        static::created(function (ShoppingCart $cart): void {
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
        $this->guardAvailability($purchasable, $quantity, 'check_on_add');

        return $this->addCustom(
            description: $purchasable->getPurchasableName(),
            price: $purchasable->getPurchasablePrice($quantity),
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
     * tiered) pricing instead.
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

        $cart = $this->exists ? $this : static::completelyNew();

        $attributes = [
            'shopping_cart_id' => $cart->id,
            'purchasable_id' => $purchasable?->getPurchasableKey(),
            'description' => $description,
            'type' => $type,
            'price_excluding_vat' => $price->amountExcludingVat,
            'price_including_vat' => $price->amountIncludingVat,
            'vat_amount' => $price->vatAmount(),
            'vat_percentage' => $price->vatPercentage,
            'currency' => $price->currency,
            'meta' => $meta,
            'visible_in_cart' => $visibleInCart,
            'custom_price' => $customPrice,
        ];

        $itemModel = config('cart.models.shopping_cart_item');
        $item = new $itemModel($attributes);
        $signature = $item->buildSignature();

        /** @var ShoppingCartItem|null $existing */
        $existing = $combine
            ? $cart->items()->where('signature', $signature)->first()
            : null;

        if ($existing) {
            $existing->increaseQuantity($quantity);

            return $existing;
        }

        $item->quantity = $quantity;
        $item->save();

        event(new ItemAdded($cart, $item));

        return $item;
    }

    /**
     * Whether the cart may still be changed: a cart confirmed for payment is
     * frozen until `confirmed_at` is cleared again.
     */
    public function isOpen(): bool
    {
        return $this->confirmed_at === null;
    }

    /**
     * Refuse any change to a cart that has been confirmed for payment.
     */
    public function assertOpen(): void
    {
        if (! $this->isOpen()) {
            throw CartLockedException::make();
        }
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

        $this->unsetRelation('items');
        $this->calculateShippingCost();
        $this->recalculateDiscount();
    }

    public function getShippingItem(): ?ShoppingCartItem
    {
        return $this->shippingItems()->first();
    }

    protected function calculateShippingCost(): void
    {
        $this->getShippingItem()?->delete();
        $this->unsetRelation('items');

        $method = $this->resolveShippingMethod();

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
        $codes = $this->discountItems()->pluck('description');

        if ($codes->isEmpty()) {
            return;
        }

        $this->discountItems()->each(fn (ShoppingCartItem $item) => $item->delete());
        $this->unsetRelation('items');

        // Reapply in the order they were added, so cumulative percentages keep
        // their meaning. A code that no longer qualifies simply drops off.
        foreach ($codes as $code) {
            $discount = config('cart.models.discount')::byCode($code);

            if ($discount) {
                rescue(fn () => $this->applyDiscount($discount), report: false);
            }
        }
    }

    /**
     * Apply a discount to the cart, or reject it with a reason.
     *
     * Codes stack only when every code on the cart — the ones already applied
     * and the new one — is marked combinable; otherwise the new code replaces
     * whatever was there. The same code never applies twice.
     */
    public function applyDiscount(Discount $discount): void
    {
        $this->assertOpen();

        try {
            if ($this->discountItems()->pluck('description')->contains($discount->discount_code)) {
                throw new DiscountException(__('This voucher is already applied to your shopping cart.'));
            }

            if (! $this->canCombineWith($discount)) {
                $this->removeDiscount();
            }

            $discount->assertAllowedOn($this);
            $price = $discount->calculateForCart($this);
            // Never signature-combine: two codes share a null purchasable and
            // would otherwise collapse into one double-quantity line.
            $this->addCustom($discount->discount_code, $price, CartItemType::Discount, visibleInCart: false, combine: false);
            $this->unsetRelation('items');
            event(new DiscountApplied($this, $discount, $price));
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
        $this->discountItems()
            ->when($code !== null, fn ($items) => $items->where('description', $code))
            ->each(fn (ShoppingCartItem $item) => $item->delete());
        $this->unsetRelation('items');
    }

    /**
     * Whether the given discount may live alongside the codes already applied.
     */
    protected function canCombineWith(Discount $discount): bool
    {
        $applied = $this->discountItems()->pluck('description');

        if ($applied->isEmpty()) {
            return true;
        }

        if (! $discount->is_combinable) {
            return false;
        }

        $discountModel = config('cart.models.discount');

        return $applied->every(
            fn (string $code): bool => (bool) $discountModel::byCode($code)?->is_combinable,
        );
    }

    /**
     * Apply the shipping method the customer picked, replacing whatever shipping
     * line the cart held. Passing null clears shipping entirely (e.g. pickup).
     * The method prices itself against the current cart, so a "free over X"
     * method lands at zero once the order is large enough.
     */
    public function selectShippingMethod(?ShippingMethod $method): void
    {
        $this->assertOpen();

        $this->getShippingItem()?->delete();
        $this->unsetRelation('items');

        if ($method) {
            $this->forceFill(['shipping_method_id' => $method->id])->saveQuietly();
            $this->addCustom($method->name, $method->priceForCart($this), CartItemType::Shipping, visibleInCart: false);
            $this->unsetRelation('items');
        } else {
            $this->forceFill(['shipping_method_id' => null])->saveQuietly();
        }

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

        $this->feeItems()->each(fn (ShoppingCartItem $item) => $item->delete());
        $this->unsetRelation('items');

        if ($price && ! $price->isZero()) {
            $this->addCustom($description, $price, CartItemType::Fee, visibleInCart: true);
            $this->unsetRelation('items');
        }
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
        $this->update([
            'user_id' => null,
            'customer_id' => null,
        ]);
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
        $this->update(['shipping_address_id' => $address->id]);
    }

    public function connectInvoiceAddress(Address $address): void
    {
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
     * Turn the cart into an order. When the settled payment amount is passed,
     * it must equal the cart's total to the cent — a mismatch means the cart
     * changed after the payment started (or the payment settled short), and an
     * order must never be created for a different amount than was paid.
     */
    public function convertToOrder(?int $expectedTotalAmount = null): Order
    {
        if ($expectedTotalAmount !== null && $expectedTotalAmount !== $this->getTotalAmount()) {
            throw PaymentAmountMismatchException::make($expectedTotalAmount, $this->getTotalAmount());
        }

        return config('cart.models.order')::createFromShoppingCart($this);
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

        $changed = [];

        foreach ($this->productItems() as $item) {
            if ($item->custom_price) {
                continue;
            }

            $purchasable = $item->resolvePurchasable();

            if (! $purchasable) {
                continue;
            }

            $current = $purchasable->getPurchasablePrice($item->quantity);

            if ($current->equals($item->price())) {
                continue;
            }

            $from = $item->price();
            $item->applyPrice($current);
            $changed[] = $item;

            event(new ItemPriceChanged($this, $item, $from, $current));
        }

        if ($changed !== []) {
            $this->unsetRelation('items');
        }

        return new Collection($changed);
    }

    /**
     * Fold another cart's product lines into this one, combining matching lines
     * and copying over a note or addresses this cart is still missing. The
     * source cart is emptied and soft-deleted.
     */
    public function mergeFrom(ShoppingCart $source): void
    {
        $this->assertOpen();

        /** @var iterable<ShoppingCartItem> $productItems */
        $productItems = $source->items()->where('type', CartItemType::Product)->get();

        foreach ($productItems as $item) {
            $this->absorbItem($item);
        }

        $this->note ??= $source->note;
        $this->shipping_address_id ??= $source->shipping_address_id;
        $this->invoice_address_id ??= $source->invoice_address_id;
        $this->save();

        $source->delete();
    }

    protected function absorbItem(ShoppingCartItem $item): void
    {
        $purchasable = $item->resolvePurchasable();

        if ($purchasable && ! $purchasable->isAvailableForPurchase($item->quantity, $this)) {
            return;
        }

        /** @var ShoppingCartItem|null $existing */
        $existing = $this->items()->where('signature', $item->signature)->first();
        if ($existing) {
            $existing->increaseQuantity($item->quantity);

            return;
        }

        $copy = $item->replicate(['shopping_cart_id']);
        $copy->shopping_cart_id = $this->id;
        $copy->save();
    }

    public function hasExcludedShipping(): bool
    {
        return false;
    }

    /*
    |--------------------------------------------------------------------------
    | Payable contract
    |--------------------------------------------------------------------------
    */

    public function getPayableDescription(): string
    {
        return __('Order').' #'.$this->display_id;
    }

    /**
     * A frozen description of what a payment for this cart covers. Payment
     * integrations (marshmallow/payable stores this as `payable_snapshot` the
     * moment a payment starts) keep it next to the payment, so the webhook can
     * prove what the settled amount bought even when the cart changed or was
     * deleted in the meantime.
     *
     * @return array<string, mixed>
     */
    public function getPayableSnapshot(): array
    {
        return [
            'cart_id' => $this->id,
            'display_id' => $this->display_id,
            'total_amount' => $this->getTotalAmount(),
            'total_vat_amount' => $this->getTotalVatAmount(),
            'lines' => $this->loadedItems()->map(fn (ShoppingCartItem $item): array => [
                'description' => $item->description,
                'type' => $item->type->value,
                'quantity' => $item->quantity,
                'unit_amount' => $item->getUnitAmount(),
                'total_amount' => $item->getTotalAmount(),
                'vat_percentage' => $item->vat_percentage,
                'currency' => $item->currency,
            ])->values()->all(),
        ];
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

    public static function getBySession(): ?ShoppingCart
    {
        $cart = static::find(session()->get(self::SESSION_KEY));

        if ($cart && ! $cart->user && ! $cart->customer && ! $cart->prospect) {
            return static::completelyNew();
        }

        return $cart;
    }

    public static function completelyNew(int $attempts = 0): ShoppingCart
    {
        try {
            $cart = static::create();
            session()->put(self::SESSION_KEY, $cart->id);
            session()->put(self::SESSION_TOKEN_KEY, $cart->guard_token);

            return $cart;
        } catch (UniqueConstraintViolationException $e) {
            if ($attempts >= 3) {
                throw $e;
            }

            return static::completelyNew($attempts + 1);
        }
    }

    public static function newWithSameProspect(ShoppingCart $cart): ShoppingCart
    {
        $new = static::completelyNew();
        $new->update(['prospect_id' => $cart->prospect_id]);

        return $new;
    }

    /**
     * The user's most recent cart that has not yet been paid for, if any.
     */
    public static function latestOpenForUser(Model $user): ?ShoppingCart
    {
        return static::query()
            ->where('user_id', $user->getKey())
            ->whereNull('confirmed_at')
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

        return is_string($token) && hash_equals($this->guard_token, $token);
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

    public function getRouteKeyName(): string
    {
        return 'id';
    }

    private function guardAvailability(Purchasable $purchasable, int $quantity, string $configKey): void
    {
        if (! config("cart.stock.{$configKey}", true)) {
            return;
        }

        if (! $purchasable->isAvailableForPurchase($quantity, $this)) {
            throw PurchasableUnavailableException::for($purchasable, $quantity);
        }
    }
}
