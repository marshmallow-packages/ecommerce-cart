<?php

declare(strict_types=1);

namespace Marshmallow\Ecommerce\Cart\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Marshmallow\Ecommerce\Cart\Concerns\CalculatesItemTotals;
use Marshmallow\Ecommerce\Cart\Contracts\CartLine;
use Marshmallow\Ecommerce\Cart\Contracts\Purchasable;
use Marshmallow\Ecommerce\Cart\Enums\CartItemType;
use Marshmallow\Ecommerce\Cart\Events\ItemQuantityChanged;
use Marshmallow\Ecommerce\Cart\Events\ItemRemoved;

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
 * @property-read ShoppingCart|null $cart
 */
class ShoppingCartItem extends Model implements CartLine
{
    use CalculatesItemTotals;
    use HasFactory;

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
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (ShoppingCartItem $item): void {
            $item->signature = $item->buildSignature();
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
        return hash('xxh128', (string) json_encode([
            'purchasable_id' => $this->purchasable_id,
            'type' => $this->type->value,
            'meta' => $this->meta,
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

    private function changeQuantity(int $quantity): self
    {
        $from = $this->quantity;

        $this->update(['quantity' => $quantity]);

        if ($from !== $quantity) {
            event(new ItemQuantityChanged($this->cart, $this, $from, $quantity));
        }

        return $this;
    }
}
