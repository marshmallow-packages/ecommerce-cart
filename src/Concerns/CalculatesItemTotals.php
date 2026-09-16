<?php

declare(strict_types=1);

namespace Marshmallow\Ecommerce\Cart\Concerns;

use Marshmallow\Ecommerce\Cart\Support\Price;

/**
 * Line-level money accessors for a cart or order item.
 *
 * Every value derives from the item's own snapshot columns; nothing is
 * recomputed from a live product price, so a line reads back exactly what it
 * was worth when it was added. The gross unit price is canonical and the line
 * total is the unit price times the quantity.
 *
 * @property int $price_excluding_vat
 * @property int $price_including_vat
 * @property int $vat_amount
 * @property float $vat_percentage
 * @property string $currency
 * @property int $quantity
 */
trait CalculatesItemTotals
{
    /**
     * The unit price of this line as an immutable value object.
     */
    public function price(): Price
    {
        return Price::fromGross($this->price_including_vat, (float) $this->vat_percentage, $this->currency);
    }

    /**
     * The line total (unit price times quantity) as an immutable value object.
     */
    public function total(): Price
    {
        return $this->price()->multiply($this->quantity);
    }

    public function getUnitAmount(): int
    {
        return $this->price_including_vat;
    }

    public function getUnitAmountWithoutVat(): int
    {
        return $this->price_excluding_vat;
    }

    public function getUnitVatAmount(): int
    {
        return $this->vat_amount;
    }

    public function getTotalAmount(): int
    {
        return $this->price_including_vat * $this->quantity;
    }

    public function getTotalAmountWithoutVat(): int
    {
        return $this->price_excluding_vat * $this->quantity;
    }

    public function getTotalVatAmount(): int
    {
        return $this->vat_amount * $this->quantity;
    }
}
