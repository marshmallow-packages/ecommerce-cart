<?php

declare(strict_types=1);

namespace Marshmallow\Ecommerce\Cart\Exceptions;

use Marshmallow\Ecommerce\Cart\Contracts\Purchasable;

class PurchasableUnavailableException extends CartException
{
    public static function for(Purchasable $purchasable, int $quantity): self
    {
        return new self(
            "\"{$purchasable->getPurchasableName()}\" is not available in the requested quantity ({$quantity}).",
        );
    }
}
