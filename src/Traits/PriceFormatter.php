<?php

namespace Marshmallow\Ecommerce\Cart\Traits;

use Marshmallow\Priceable\Facades\Price;

use function Webmozart\Assert\Tests\StaticAnalysis\methodExists;

trait PriceFormatter
{
    public function getFormatted(string $method_column_or_value): string
    {
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
