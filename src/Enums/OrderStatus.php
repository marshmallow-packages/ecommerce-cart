<?php

declare(strict_types=1);

namespace Marshmallow\Ecommerce\Cart\Enums;

enum OrderStatus: string
{
    case Pending = 'PENDING';
    case Canceled = 'CANCELED';
    case Completed = 'COMPLETED';
    case Refunded = 'REFUNDED';

    /**
     * Whether an order may move from this status to the given one. A pending
     * order can go anywhere; a completed order can only be refunded; a
     * canceled order can be reopened; a refund is final.
     */
    public function canTransitionTo(self $to): bool
    {
        return in_array($to, match ($this) {
            self::Pending => [self::Canceled, self::Completed, self::Refunded],
            self::Completed => [self::Refunded],
            self::Canceled => [self::Pending],
            self::Refunded => [],
        }, true);
    }
}
