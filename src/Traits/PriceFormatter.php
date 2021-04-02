<?php

namespace Marshmallow\Ecommerce\Cart\Traits;

use Marshmallow\Priceable\Facades\Price;

trait PriceFormatter
{
    public function getFormatted(string $method): string
    {
        $value = $this->{$method}();
        return Price::formatAmount($value);
    }
}
