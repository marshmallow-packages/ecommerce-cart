<?php

namespace Marshmallow\Ecommerce\Cart\Traits;

use Marshmallow\Priceable\Facades\Price;

trait PriceFormatter
{
    public function getFormatted(?string $method_column_or_value = null): string
    {
        $method_column_or_value = $method_column_or_value ?? 0;

        if (method_exists($this, $method_column_or_value)) {
            $value = $this->{$method_column_or_value}();
        } elseif (isset($this->$method_column_or_value)) {
            $value = $this->{$method_column_or_value};
        } else {
            $value = $method_column_or_value;
        }

        return Price::formatAmount($value);
    }
}
