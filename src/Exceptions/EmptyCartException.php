<?php

declare(strict_types=1);

namespace Marshmallow\Ecommerce\Cart\Exceptions;

/**
 * Thrown when a cart without any product line is converted into an order.
 */
class EmptyCartException extends CartException
{
    public static function make(): self
    {
        return new self('The cart holds no products; refusing to create an empty order.');
    }
}
