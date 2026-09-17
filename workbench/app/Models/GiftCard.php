<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Model;
use Marshmallow\Ecommerce\Cart\Contracts\Purchasable;
use Marshmallow\Ecommerce\Cart\Models\ShoppingCart;
use Marshmallow\Ecommerce\Cart\Support\Price;

/**
 * A second purchasable type next to Product, proving a cart can hold lines
 * from more than one model.
 *
 * @property int $id
 * @property string $code
 * @property int $value_cents
 */
class GiftCard extends Model implements Purchasable
{
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['value_cents' => 'integer'];
    }

    public function getPurchasableKey(): int|string
    {
        return $this->getKey();
    }

    public function getPurchasableName(): string
    {
        return 'Cadeaukaart '.$this->code;
    }

    public function getPurchasablePrice(int $quantity = 1, ?ShoppingCart $cart = null): Price
    {
        return Price::fromGross($this->value_cents, 0.0, 'EUR');
    }

    public function isAvailableForPurchase(int $quantity, ?ShoppingCart $cart = null): bool
    {
        return true;
    }
}
