<?php

declare(strict_types=1);

namespace Marshmallow\Ecommerce\Cart\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Marshmallow\Ecommerce\Cart\Concerns\CalculatesItemTotals;
use Marshmallow\Ecommerce\Cart\Concerns\HasPurchasable;
use Marshmallow\Ecommerce\Cart\Contracts\CartLine;
use Marshmallow\Ecommerce\Cart\Contracts\Purchasable;
use Marshmallow\Ecommerce\Cart\Enums\CartItemType;
use Marshmallow\Ecommerce\Cart\Events\ItemQuantityChanged;
use Marshmallow\Ecommerce\Cart\Events\ItemRemoved;
use Marshmallow\Ecommerce\Cart\Exceptions\PurchasableUnavailableException;
use Marshmallow\Ecommerce\Cart\Support\Price;

/**
 * A single line in a shopping cart.
 *
 * @property int $id
 * @property string $shopping_cart_id
 * @property string|null $purchasable_type
 * @property int|string|null $purchasable_id
 * @property string $description
 * @property CartItemType $type
 * @property int $quantity
 * @property int $price_excluding_vat
 * @property int $price_including_vat
 * @property int $vat_amount
 * @property float $vat_percentage
 * @property string $currency
 * @property array<string, mixed>|null $meta
 * @property string $signature
 * @property bool $visible_in_cart
 * @property bool $custom_price
 * @property-read ShoppingCart|null $cart
 */
class ShoppingCartItem extends Model implements CartLine
{
    use CalculatesItemTotals;
    use HasPurchasable;
    use SoftDeletes;

    protected $guarded = [];

    /**
     * Every change to a line is activity on its cart, which keeps the
     * housekeeping command from flagging or pruning a cart that is in use.
     *
     * @var array<int, string>
     */
    protected $touches = ['cart'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => CartItemType::class,
            'meta' => 'array',
            'quantity' => 'integer',
            'price_excluding_vat' => 'integer',
            'price_including_vat' => 'integer',
            'vat_amount' => 'integer',
            'vat_percentage' => 'float',
            'visible_in_cart' => 'boolean',
            'custom_price' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (ShoppingCartItem $item): void {
            // A confirmed cart is frozen down to its lines: the customer is at
            // the payment provider and the amounts may no longer move.
            $item->cart?->assertOpen();

            $item->signature = $item->buildSignature();
        });

        static::deleting(function (ShoppingCartItem $item): void {
            $item->cart?->assertOpen();
        });

        static::created(function (ShoppingCartItem $item): void {
            $item->cart?->shoppingCartContentChanged($item);
        });

        static::updated(function (ShoppingCartItem $item): void {
            $item->cart?->shoppingCartContentChanged($item);
        });

        static::deleted(function (ShoppingCartItem $item): void {
            if ($item->isProduct() && $cart = $item->cart) {
                event(new ItemRemoved($cart, $item));
            }

            $item->cart?->shoppingCartContentChanged($item);
        });
    }

    public function isShippingCost(): bool
    {
        return $this->type === CartItemType::Shipping;
    }

    public function isDiscount(): bool
    {
        return $this->type === CartItemType::Discount;
    }

    public function isProduct(): bool
    {
        return $this->type === CartItemType::Product;
    }

    public function isFee(): bool
    {
        return $this->type === CartItemType::Fee;
    }

    /**
     * Set the quantity of this line. Zero (or less) removes the line: a
     * storefront that lets the customer type a quantity gets the natural
     * behaviour without a special case.
     */
    public function setQuantity(int $quantity): self
    {
        if ($quantity < 1) {
            $this->delete();

            return $this;
        }

        return $this->changeQuantity($quantity);
    }

    public function increaseQuantity(int $amount = 1): self
    {
        return $this->changeQuantity($this->quantity + $amount);
    }

    public function decreaseQuantity(int $amount = 1): self
    {
        return $this->changeQuantity(max(1, $this->quantity - $amount));
    }

    /**
     * A stable fingerprint of what this line represents, used to combine
     * repeat additions of the same purchasable with the same options.
     */
    public function buildSignature(): string
    {
        return static::signatureFor($this->purchasable_type, $this->purchasable_id, $this->type, $this->meta);
    }

    /**
     * The signature for a line with the given identity, shared with the
     * upgrade migrations so existing rows are signed exactly like new ones.
     *
     * @param  array<string, mixed>|null  $meta
     */
    public static function signatureFor(?string $purchasableType, int|string|null $purchasableId, CartItemType $type, ?array $meta): string
    {
        // The key is stored in a string column, so a line read back from the
        // database carries "42" where the purchasable handed in 42. Normalise
        // before hashing, or the signature would change on the first re-save
        // and later additions of the same product would stop combining.
        return hash('xxh128', (string) json_encode([
            'purchasable_type' => $purchasableType,
            'purchasable_id' => $purchasableId === null ? null : (string) $purchasableId,
            'type' => $type->value,
            'meta' => $meta,
        ]));
    }

    /**
     * The morph type stored for a purchasable: its morph class for an Eloquent
     * model (so a host's morph map applies), its class name otherwise.
     */
    public static function morphTypeOf(Purchasable $purchasable): string
    {
        return $purchasable instanceof Model ? $purchasable->getMorphClass() : $purchasable::class;
    }

    public function scopeVisible(Builder $query): void
    {
        $query->where('visible_in_cart', true);
    }

    public function cart(): BelongsTo
    {
        return $this->belongsTo(config('cart.models.shopping_cart'), 'shopping_cart_id');
    }

    /**
     * Overwrite this line's price snapshot with a new unit price.
     */
    public function applyPrice(Price $price): self
    {
        $this->update(static::priceAttributes($price));

        return $this;
    }

    /**
     * The snapshot columns a unit price is stored in.
     *
     * @return array<string, int|float|string>
     */
    public static function priceAttributes(Price $price): array
    {
        return [
            'price_excluding_vat' => $price->amountExcludingVat,
            'price_including_vat' => $price->amountIncludingVat,
            'vat_amount' => $price->vatAmount(),
            'vat_percentage' => $price->vatPercentage,
            'currency' => $price->currency,
        ];
    }

    /**
     * Move to a new quantity in a single write: the purchasable is asked
     * whether it can supply that many, and a purchasable-priced line takes
     * the tier price for the new quantity along in the same update.
     */
    private function changeQuantity(int $quantity): self
    {
        $from = $this->quantity;

        if ($from === $quantity) {
            return $this;
        }

        $attributes = ['quantity' => $quantity];
        $purchasable = $this->custom_price ? null : $this->resolvePurchasable();

        if ($purchasable) {
            if ($quantity > $from && config('cart.stock.check_on_add', true) && ! $purchasable->isAvailableForPurchase($quantity, $this->cart)) {
                throw PurchasableUnavailableException::for($purchasable, $quantity);
            }

            $price = $purchasable->getPurchasablePrice($quantity, $this->cart);

            if (! $price->equals($this->price())) {
                $attributes += static::priceAttributes($price);
            }
        }

        $this->update($attributes);

        if ($cart = $this->cart) {
            event(new ItemQuantityChanged($cart, $this, $from, $quantity));
        }

        return $this;
    }
}
