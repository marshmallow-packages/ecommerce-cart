<?php

namespace Marshmallow\Ecommerce\Cart\Traits;

trait Totals
{
    public function productCount(): int
    {
        return intval($this->items()->visable()->sum('quantity'));
    }

    public function getTotalAmountWithoutShipping(): int
    {
        $total = 0;
        foreach ($this->items()->visable()->get() as $item) {
            $price_including_vat = $item->price_including_vat;
            $total += ($price_including_vat * $item->quantity);
        }
        return $total;
    }

    public function getTotalAmountWithoutShippingAndWithoutVat(): int
    {
        $total = 0;
        foreach ($this->items()->visable()->get() as $item) {
            $price_without_vat = $item->price_excluding_vat;
            $total += ($price_without_vat * $item->quantity);
        }
        return $total;
    }

    public function getShippingAmount(): int
    {
        if ($shipping = $this->getShippingItem()) {
            return $shipping->price_including_vat;
        }
        return 0;
    }

    public function getShippingAmountIncludingVat(): int
    {
        if ($shipping = $this->getShippingItem()) {
            return $shipping->price_including_vat;
        }
        return 0;
    }

    public function getShippingAmountWithoutVat(): int
    {
        if ($shipping = $this->getShippingItem()) {
            return $shipping->price_excluding_vat;
        }
        return 0;
    }

    public function getShippingVatAmount(): int
    {
        if ($shipping = $this->getShippingItem()) {
            return $shipping->vat_amount;
        }
        return 0;
    }

    public function getTotalAmount(): int
    {
        return $this->getTotalAmountWithoutShipping() + $this->getShippingAmount();
    }

    public function getTotalAmountIncludingVat(): int
    {
        return $this->getTotalAmount();
    }

    public function getTotalAmountWithoutVat(): int
    {
        return $this->getTotalAmountWithoutShippingAndWithoutVat() + $this->getShippingAmountWithoutVat();
    }

    public function getTotalVatAmount(): int
    {
        return $this->getTotalAmount() - $this->getTotalAmountWithoutVat();
    }
}
