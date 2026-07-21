<?php

declare(strict_types=1);

namespace Marshmallow\Ecommerce\Cart\Enums;

enum OrderStatus: string
{
    case Pending = 'PENDING';
    case Canceled = 'CANCELED';
    case Completed = 'COMPLETED';
}
