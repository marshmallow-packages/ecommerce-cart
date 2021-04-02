<?php

namespace Marshmallow\Ecommerce\Cart\Traits;

trait CartItemTotals
{
    public function getUnitAmount(): int
    {
        return $this->display_price;
    }

    public function getUnitAmountWithoutVat(): int
    {
        return $this->price_excluding_vat;
    }

    public function getUnitAmountWithVat(): int
    {
        return $this->price_including_vat;
    }

    public function getUnitVatAmount(): int
    {
        return $this->vat_amount;
    }

    public function getTotalAmount(): int
    {
        return $this->getUnitAmount() * $this->quantity;
    }

    public function getTotalAmountWithoutVat(): int
    {
        return $this->getUnitAmountWithoutVat() * $this->quantity;
    }

    public function getTotalAmountWithVat(): int
    {
        return $this->getUnitVatAmount() * $this->quantity;
    }

    public function getTotalVatAmount(): int
    {
        return $this->getUnitVatAmount() * $this->quantity;
    }
}
