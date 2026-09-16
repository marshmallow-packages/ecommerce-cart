<?php

declare(strict_types=1);

namespace Marshmallow\Ecommerce\Cart\Exceptions;

/**
 * Thrown when a paid amount does not match the cart it is supposed to settle.
 * This is the last line of defence behind the cart lock: an order must never
 * be created for a different amount than the customer actually paid.
 */
class PaymentAmountMismatchException extends CartException
{
    public static function make(int $expected, int $actual): self
    {
        return new self("The payment settled {$expected} cents but the cart totals {$actual} cents; refusing to create the order.");
    }
}
