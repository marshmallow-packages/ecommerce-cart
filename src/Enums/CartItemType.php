<?php

declare(strict_types=1);

namespace Marshmallow\Ecommerce\Cart\Enums;

enum CartItemType: string
{
    case Product = 'PRODUCT';
    case Discount = 'DISCOUNT';
    case Shipping = 'SHIPPING';
    case Fee = 'FEE';

    public function isProduct(): bool
    {
        return $this === self::Product;
    }

    public function isDiscount(): bool
    {
        return $this === self::Discount;
    }

    public function isShipping(): bool
    {
        return $this === self::Shipping;
    }

    public function isFee(): bool
    {
        return $this === self::Fee;
    }
}
