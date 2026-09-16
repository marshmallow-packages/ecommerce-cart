<?php

declare(strict_types=1);

namespace Marshmallow\Ecommerce\Cart\Exceptions;

use Marshmallow\Ecommerce\Cart\Enums\OrderStatus;

/**
 * Thrown when an order is moved to a status its current status does not
 * allow, e.g. completing an order that was canceled.
 */
class InvalidOrderStatusTransitionException extends CartException
{
    public static function make(OrderStatus $from, OrderStatus $to): self
    {
        return new self("An order cannot move from {$from->value} to {$to->value}.");
    }
}
