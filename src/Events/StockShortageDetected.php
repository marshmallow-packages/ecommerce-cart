<?php

declare(strict_types=1);

namespace Marshmallow\Ecommerce\Cart\Events;

use Marshmallow\Ecommerce\Cart\Models\Order;

/**
 * An order was created for lines whose purchasable can no longer supply the
 * quantity that was paid for. The order stands — the money is in — and the
 * host decides between backorder, partial delivery or refund.
 */
final class StockShortageDetected
{
    /**
     * @param  array<int, array{purchasable_type: string|null, purchasable_id: int|string|null, description: string, quantity: int}>  $shortages
     */
    public function __construct(
        public Order $order,
        public array $shortages,
    ) {}
}
