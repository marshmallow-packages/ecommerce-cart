<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Marshmallow\Ecommerce\Cart\Contracts\HasPurchasableCategories;
use Marshmallow\Ecommerce\Cart\Contracts\Purchasable;
use Marshmallow\Ecommerce\Cart\Models\ShoppingCart;
use Marshmallow\Ecommerce\Cart\Support\Price;

/**
 * A minimal purchasable used by the package test suite. Stands in for whatever
 * product model a host application maps into `cart.models.product`.
 *
 * @property int $id
 * @property string $name
 * @property int $price_cents
 * @property float $vat_percentage
 * @property int $stock
 * @property array<int, int>|null $category_ids
 * @property array<string, int>|null $price_tiers
 */
class Product extends Model implements HasPurchasableCategories, Purchasable
{
    use HasFactory;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price_cents' => 'integer',
            'vat_percentage' => 'float',
            'stock' => 'integer',
            'category_ids' => 'array',
            'price_tiers' => 'array',
        ];
    }

    public function getPurchasableKey(): int|string
    {
        return $this->getKey();
    }

    public function getPurchasableName(): string
    {
        return $this->name;
    }

    public function getPurchasablePrice(int $quantity = 1): Price
    {
        $cents = $this->price_cents;

        // Tiers map a minimum quantity to a unit price in cents; the highest
        // tier the quantity reaches wins.
        foreach ($this->price_tiers ?? [] as $minimum => $unitCents) {
            if ($quantity >= (int) $minimum) {
                $cents = (int) $unitCents;
            }
        }

        return Price::fromGross($cents, $this->vat_percentage, 'EUR');
    }

    public function isAvailableForPurchase(int $quantity, ?ShoppingCart $cart = null): bool
    {
        return $this->stock >= $quantity;
    }

    /**
     * @return array<int, int>
     */
    public function getPurchasableCategoryKeys(): array
    {
        return $this->category_ids ?? [];
    }
}
