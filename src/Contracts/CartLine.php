<?php

declare(strict_types=1);

namespace Marshmallow\Ecommerce\Cart\Contracts;

/**
 * A money-carrying line, whether it still sits in a cart or has been copied
 * onto an order. The totals concern sums lines through this contract, so the
 * same arithmetic serves both models.
 */
interface CartLine
{
    public function getTotalAmount(): int;

    public function getTotalAmountWithoutVat(): int;
}
