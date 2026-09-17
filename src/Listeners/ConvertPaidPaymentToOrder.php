<?php

declare(strict_types=1);

namespace Marshmallow\Ecommerce\Cart\Listeners;

use Illuminate\Database\Eloquent\Model;
use Marshmallow\Ecommerce\Cart\Events\PaymentSnapshotMismatch;
use Marshmallow\Ecommerce\Cart\Exceptions\PaymentAmountMismatchException;
use Marshmallow\Ecommerce\Cart\Models\ShoppingCart;
use Marshmallow\Payable\Events\PaymentStatusPaid;

/**
 * When a payment for a cart settles, create the order from the snapshot the
 * payment was started with. The live cart plays no part in what the order
 * contains: a cart that was reopened and changed after the payment started
 * cannot leak into it. The settled amount has to match the snapshot to the
 * cent; otherwise nothing is created and {@see PaymentSnapshotMismatch} tells
 * the host to look.
 */
class ConvertPaidPaymentToOrder
{
    public function handle(PaymentStatusPaid $event): void
    {
        if (! config('cart.payable.convert_on_paid', true)) {
            return;
        }

        $payment = $event->payment;
        $class = Model::getActualClassNameForMorph((string) $payment->payable_type);

        if (! is_string($class) || ! is_a($class, ShoppingCart::class, true)) {
            return;
        }

        $cart = $payment->payable;

        if (! $cart instanceof ShoppingCart) {
            return;
        }

        $snapshot = $payment->payable_snapshot;
        $paid = (int) $payment->paid_amount;

        // A payment started before snapshots existed: the live cart is all
        // there is, guarded by the amount check.
        if (! is_array($snapshot)) {
            try {
                $cart->convertToOrder(expectedTotalAmount: $paid);
            } catch (PaymentAmountMismatchException) {
                event(new PaymentSnapshotMismatch($cart, $payment, $cart->getTotalAmount(), $paid));
            }

            return;
        }

        $expected = (int) ($snapshot['total_amount'] ?? -1);

        if ($expected !== $paid || (int) $payment->total_amount !== $expected) {
            event(new PaymentSnapshotMismatch($cart, $payment, $expected, $paid));

            return;
        }

        config('cart.models.order')::createFromSnapshot($snapshot, $cart, $payment);
    }
}
