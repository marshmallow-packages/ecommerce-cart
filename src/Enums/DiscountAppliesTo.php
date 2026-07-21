<?php

declare(strict_types=1);

namespace Marshmallow\Ecommerce\Cart\Enums;

enum DiscountAppliesTo: string
{
    case All = 'all';
    case Categories = 'specific_categories';
    case Products = 'specific_products';
}
