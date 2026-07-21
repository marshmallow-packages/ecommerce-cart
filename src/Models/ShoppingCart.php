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
use Marshmallow\Ecommerce\Cart\Events\ShippingCalculated;
use Marshmallow\Ecommerce\Cart\Exceptions\DiscountException;
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
                $cart->customer_id = $prospect->getCustomer()?->id;
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
            price: $purchasable->getPurchasablePrice(),
            type: CartItemType::Product,
            quantity: $quantity,
            purchasable: $purchasable,
            meta: $meta,
        );
    }

    /**
     * Add an arbitrary line to the cart, snapshotting the given price.
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
    ): ShoppingCartItem {
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

        $method = config('cart.models.shipping_method')::calculateFromCart($this);

        if (! $method) {
            $this->forceFill(['shipping_method_id' => null])->saveQuietly();
            event(new ShippingCalculated($this, null, Price::zero()));

            return;
        }

        $this->forceFill(['shipping_method_id' => $method->id])->saveQuietly();

        $price = $method->toPrice();
        $this->addCustom($method->name, $price, CartItemType::Shipping, visibleInCart: false);

        event(new ShippingCalculated($this, $method, $price));
    }

    protected function recalculateDiscount(): void
    {
        $discountItem = $this->discountItems()->first();

        if (! $discountItem) {
            return;
        }

        $code = $discountItem->description;
        $discountItem->delete();
        $this->unsetRelation('items');

        $discount = config('cart.models.discount')::byCode($code);

        if ($discount) {
            $this->applyDiscount($discount);
        }
    }

    /**
     * Apply a discount to the cart, or reject it with a reason.
     */
    public function applyDiscount(Discount $discount): void
    {
        try {
            $discount->assertAllowedOn($this);
            $price = $discount->calculateForCart($this);
            $this->addCustom($discount->discount_code, $price, CartItemType::Discount, visibleInCart: false);
            $this->unsetRelation('items');
            event(new DiscountApplied($this, $discount, $price));
        } catch (DiscountException $e) {
            event(new DiscountRejected($this, $discount, $e->getMessage()));

            throw $e;
        }
    }

    public function removeDiscount(): void
    {
        $this->discountItems()->each(fn (ShoppingCartItem $item) => $item->delete());
        $this->unsetRelation('items');
    }

    /**
     * Apply the shipping method the customer picked, replacing whatever shipping
     * line the cart held. Passing null clears shipping entirely (e.g. pickup).
     * The method prices itself against the current cart, so a "free over X"
     * method lands at zero once the order is large enough.
     */
    public function selectShippingMethod(?ShippingMethod $method): void
    {
        $this->getShippingItem()?->delete();
        $this->unsetRelation('items');

        if (! $method) {
            $this->forceFill(['shipping_method_id' => null])->saveQuietly();

            return;
        }

        $this->forceFill(['shipping_method_id' => $method->id])->saveQuietly();
        $this->addCustom($method->name, $method->priceForCart($this), CartItemType::Shipping, visibleInCart: false);
        $this->unsetRelation('items');
    }

    /**
     * Set (or clear) a single fee line, such as a payment surcharge. Passing
     * null or a zero price removes it, so switching to a method without a
     * surcharge leaves no stray line behind.
     */
    public function setFee(string $description, ?Price $price): void
    {
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

    public function convertToOrder(): Order
    {
        return config('cart.models.order')::createFromShoppingCart($this);
    }

    /**
     * Fold another cart's product lines into this one, combining matching lines
     * and copying over a note or addresses this cart is still missing. The
     * source cart is emptied and soft-deleted.
     */
    public function mergeFrom(ShoppingCart $source): void
    {
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
        return $this->hasMany(config('cart.models.shopping_cart_item'));
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
