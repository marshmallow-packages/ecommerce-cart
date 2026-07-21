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

    public function getPurchasablePrice(): Price
    {
        return Price::fromGross($this->price_cents, $this->vat_percentage, 'EUR');
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
