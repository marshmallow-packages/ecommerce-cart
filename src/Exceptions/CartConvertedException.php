<?php

declare(strict_types=1);

namespace Marshmallow\Ecommerce\Cart\Exceptions;

/**
 * Thrown when something tries to change, confirm or reopen a cart that has
 * already become an order. A converted cart is history; start a new one.
 */
class CartConvertedException extends CartLockedException
{
    public static function make(): self
    {
        return new self('The cart has already been converted into an order and can no longer change.');
    }
}
