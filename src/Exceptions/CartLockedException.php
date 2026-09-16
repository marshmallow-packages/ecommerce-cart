<?php

declare(strict_types=1);

namespace Marshmallow\Ecommerce\Cart\Exceptions;

/**
 * Thrown when something tries to change a cart that has been confirmed for
 * payment. A confirmed cart is frozen: the customer is at the payment
 * provider, and what they pay for must be exactly what the order becomes.
 * Reopening (clearing `confirmed_at`) is the only way back in.
 */
class CartLockedException extends CartException
{
    public static function make(): self
    {
        return new self('The cart is confirmed for payment and can no longer change. Clear confirmed_at to reopen it.');
    }
}
