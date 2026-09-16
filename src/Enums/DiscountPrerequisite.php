<?php

declare(strict_types=1);

namespace Marshmallow\Ecommerce\Cart\Enums;

enum DiscountPrerequisite: string
{
    case None = 'none';
    case PurchaseAmount = 'prerequisite_purchase_amount';
    case Quantity = 'prerequisite_quantity';
}
