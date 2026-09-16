<?php

declare(strict_types=1);

namespace Marshmallow\Ecommerce\Cart\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Marshmallow\Ecommerce\Cart\Concerns\CalculatesItemTotals;
use Marshmallow\Ecommerce\Cart\Contracts\CartLine;
use Marshmallow\Ecommerce\Cart\Contracts\Purchasable;
use Marshmallow\Ecommerce\Cart\Enums\CartItemType;
use Marshmallow\Ecommerce\Cart\Events\ItemQuantityChanged;
use Marshmallow\Ecommerce\Cart\Events\ItemRemoved;
use Marshmallow\Ecommerce\Cart\Support\Price;

/**
 * A single line in a shopping cart.
 *
 * @property string $shopping_cart_id
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
    use HasFactory;
    use SoftDeletes;

    protected $guarded = [];

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

    public function setQuantity(int $quantity): self
    {
        return $this->changeQuantity(max(1, $quantity));
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
        return static::signatureFor($this->purchasable_id, $this->type, $this->meta);
    }

    /**
     * The signature for a line with the given identity, shared with the
     * upgrade migration so legacy rows are signed exactly like new ones.
     *
     * @param  array<string, mixed>|null  $meta
     */
    public static function signatureFor(int|string|null $purchasableId, CartItemType $type, ?array $meta): string
    {
        // The key is stored in a string column, so a line read back from the
        // database carries "42" where the purchasable handed in 42. Normalise
        // before hashing, or the signature would change on the first re-save
        // and later additions of the same product would stop combining.
        return hash('xxh128', (string) json_encode([
            'purchasable_id' => $purchasableId === null ? null : (string) $purchasableId,
            'type' => $type->value,
            'meta' => $meta,
        ]));
    }

    public function scopeVisible(Builder $query): void
    {
        $query->where('visible_in_cart', true);
    }

    public function cart(): BelongsTo
    {
        return $this->belongsTo(config('cart.models.shopping_cart'), 'shopping_cart_id');
    }

    public function purchasable(): BelongsTo
    {
        return $this->belongsTo(config('cart.models.product'), 'purchasable_id');
    }

    /**
     * Resolve the live purchasable model behind this line, if it still exists.
     */
    public function resolvePurchasable(): ?Purchasable
    {
        $purchasable = $this->purchasable;

        return $purchasable instanceof Purchasable ? $purchasable : null;
    }

    /**
     * Overwrite this line's price snapshot with a new unit price.
     */
    public function applyPrice(Price $price): self
    {
        $this->update([
            'price_excluding_vat' => $price->amountExcludingVat,
            'price_including_vat' => $price->amountIncludingVat,
            'vat_amount' => $price->vatAmount(),
            'vat_percentage' => $price->vatPercentage,
            'currency' => $price->currency,
        ]);

        return $this;
    }

    private function changeQuantity(int $quantity): self
    {
        $from = $this->quantity;

        $this->update(['quantity' => $quantity]);

        if ($from !== $quantity) {
            $this->repriceForQuantity($quantity);
            event(new ItemQuantityChanged($this->cart, $this, $from, $quantity));
        }

        return $this;
    }

    /**
     * A purchasable-priced line follows the purchasable's tier for its new
     * quantity; a line whose price was chosen by the caller keeps it.
     */
    private function repriceForQuantity(int $quantity): void
    {
        if ($this->custom_price) {
            return;
        }

        $purchasable = $this->resolvePurchasable();

        if (! $purchasable) {
            return;
        }

        $price = $purchasable->getPurchasablePrice($quantity);

        if (! $price->equals($this->price())) {
            $this->applyPrice($price);
        }
    }
}
