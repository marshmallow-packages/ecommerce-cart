<?php

declare(strict_types=1);

namespace Marshmallow\Ecommerce\Cart\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Marshmallow\Ecommerce\Cart\Support\Price;

/**
 * A shipping option, priced with the same VAT-inclusive snapshot columns as a
 * cart line. Which method applies to a cart is decided by its conditions.
 *
 * @property string $name
 * @property int $price_including_vat
 * @property int $price_excluding_vat
 * @property int $vat_amount
 * @property float $vat_percentage
 * @property string $currency
 * @property int|null $free_from_amount
 * @property Carbon|null $valid_from
 * @property Carbon|null $valid_till
 * @property Collection<int, ShippingMethodCondition> $conditions
 */
class ShippingMethod extends Model
{
    use HasFactory;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'valid_from' => 'datetime',
            'valid_till' => 'datetime',
            'price_including_vat' => 'integer',
            'price_excluding_vat' => 'integer',
            'vat_amount' => 'integer',
            'vat_percentage' => 'float',
            'free_from_amount' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (ShippingMethod $method): void {
            $price = Price::fromGross(
                (int) $method->price_including_vat,
                (float) $method->vat_percentage,
                $method->currency ?: null,
            );

            $method->price_excluding_vat = $price->amountExcludingVat;
            $method->vat_amount = $price->vatAmount();
            $method->currency = $price->currency;
        });
    }

    public function toPrice(): Price
    {
        return Price::fromGross($this->price_including_vat, (float) $this->vat_percentage, $this->currency);
    }

    /**
     * This method's price for a given cart. It is free once the cart's product
     * subtotal reaches `free_from_amount` (a "free shipping over X" threshold);
     * otherwise it costs its set price.
     */
    public function priceForCart(ShoppingCart $cart): Price
    {
        if ($this->free_from_amount !== null && $cart->getSubtotal() >= $this->free_from_amount) {
            return Price::zero((float) $this->vat_percentage, $this->currency);
        }

        return $this->toPrice();
    }

    /**
     * The shipping method that applies to the given cart, or null when none do.
     *
     * A method applies when the cart's product subtotal falls inside one of the
     * method's conditions; a method with no conditions applies to any cart. The
     * first active, matching method (by sort order) wins.
     */
    public static function calculateFromCart(ShoppingCart $cart): ?ShippingMethod
    {
        if ($cart->hasExcludedShipping()) {
            return null;
        }

        $methods = static::currentlyActive()->with('conditions')->get();

        if ($methods->isEmpty()) {
            return null;
        }

        $subtotal = $cart->getSubtotal();

        foreach ($methods as $method) {
            if ($method->appliesToSubtotal($subtotal)) {
                return $method;
            }
        }

        return null;
    }

    public function appliesToSubtotal(int $subtotal): bool
    {
        if ($this->conditions->isEmpty()) {
            return true;
        }

        return $this->conditions->contains(
            fn (ShippingMethodCondition $condition): bool => $condition->matches($subtotal),
        );
    }

    public function scopeCurrentlyActive(Builder $query): void
    {
        $now = now();

        $query
            ->where(fn (Builder $q) => $q->whereNull('valid_from')->orWhere('valid_from', '<=', $now))
            ->where(fn (Builder $q) => $q->whereNull('valid_till')->orWhere('valid_till', '>=', $now))
            ->orderBy('sort');
    }

    public function conditions(): HasMany
    {
        return $this->hasMany(config('cart.models.shipping_method_condition'));
    }
}
