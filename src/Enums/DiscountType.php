<?php

declare(strict_types=1);

namespace Marshmallow\Ecommerce\Cart\Enums;

enum DiscountType: string
{
    case FixedAmount = 'fixed_amount';
    case Percentage = 'percentage';
    case FreeShipping = 'free_shipping';
}
