<?php

declare(strict_types=1);

namespace Marshmallow\Ecommerce\Cart\Exceptions;

/**
 * Thrown when a line in one currency is added to a cart that already holds
 * lines in another. Totals are plain sums of cents, so a cart must be
 * single-currency for any of its figures to mean anything.
 */
class CurrencyMismatchException extends CartException
{
    public static function make(string $cartCurrency, string $lineCurrency): self
    {
        return new self("Cannot add a {$lineCurrency} line to a cart priced in {$cartCurrency}.");
    }
}
