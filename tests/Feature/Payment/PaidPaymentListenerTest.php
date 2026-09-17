<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Marshmallow\Ecommerce\Cart\Events\OrderCreated;
use Marshmallow\Ecommerce\Cart\Events\PaymentSnapshotMismatch;
use Marshmallow\Ecommerce\Cart\Exceptions\EmptyCartException;
use Marshmallow\Ecommerce\Cart\Models\Order;
use Marshmallow\Ecommerce\Cart\Models\ShoppingCart;
use Marshmallow\Payable\Events\PaymentStatusPaid;
use Marshmallow\Payable\Models\Payment;
use Marshmallow\Payable\Models\PaymentProvider;
use Marshmallow\Payable\Models\PaymentType;
use Marshmallow\Payable\Payable;

function idealType(): PaymentType
{
    $provider = PaymentProvider::firstOrCreate(['slug' => 'mollie'], ['name' => 'Mollie', 'type' => Payable::MOLLIE, 'active' => true]);

    return PaymentType::firstOrCreate(['slug' => 'ideal'], ['payment_provider_id' => $provider->id, 'name' => 'iDEAL', 'simple_checkout' => false, 'active' => true]);
}

function paymentFor(ShoppingCart $cart, ?array $snapshot, int $total, int $paid): Payment
{
    $type = idealType();

    return Payment::withoutEvents(fn () => Payment::create([
        'id' => (string) Str::uuid(),
        'payable_type' => $cart->getMorphClass(),
        'payable_id' => $cart->id,
        'payment_provider_id' => $type->payment_provider_id,
        'payment_type_id' => $type->id,
        'simple_checkout' => false,
        'total_amount' => $total,
        'paid_amount' => $paid,
        'payable_snapshot' => $snapshot,
        'status' => Payment::STATUS_PAID,
        'started' => now(),
        'start_ip' => '127.0.0.1',
    ]));
}

it('confirms the cart and freezes its snapshot when a payment starts', function (): void {
    $cart = checkoutReadyCart(1000);

    $payment = $cart->startPayment(idealType(), is_custom: true);

    expect($payment)->toBeInstanceOf(Payment::class)
        ->and($cart->fresh()->isConfirmed())->toBeTrue()
        ->and($payment->total_amount)->toBe(1000)
        ->and($payment->payable_snapshot['total_amount'])->toBe(1000)
        ->and($payment->payable_snapshot['fingerprint'])->toHaveLength(64)
        ->and($payment->payable->is($cart))->toBeTrue();
});

it('refuses to start a payment for a cart that cannot be confirmed', function (): void {
    $cart = ShoppingCart::completelyNew();

    expect(fn () => $cart->startPayment(idealType(), is_custom: true))
        ->toThrow(EmptyCartException::class)
        ->and(Payment::count())->toBe(0);
});

it('creates the order from the payment snapshot when the payment is paid', function (): void {
    Event::fake([OrderCreated::class]);
    $cart = checkoutReadyCart(1000);
    $payment = $cart->startPayment(idealType(), is_custom: true);

    event(new PaymentStatusPaid(tap($payment)->forceFill(['paid_amount' => 1000, 'status' => Payment::STATUS_PAID])));

    $order = Order::sole();
    expect($order->payment_id)->toBe($payment->id)
        ->and($order->total_including_vat)->toBe(1000)
        ->and($cart->fresh()->isConverted())->toBeTrue();
    Event::assertDispatched(OrderCreated::class);
});

it('runs the whole flow off the payment model itself', function (): void {
    $cart = checkoutReadyCart(2500);
    $payment = $cart->startPayment(idealType(), is_custom: true);

    // What a provider webhook does: settle the payment.
    $payment->update(['paid_amount' => 2500, 'status' => Payment::STATUS_PAID]);

    expect(Order::count())->toBe(1)
        ->and(Order::sole()->payment_id)->toBe($payment->id);
});

it('does not build an order from the live cart when the payment carries a snapshot', function (): void {
    $cart = checkoutReadyCart(1000);
    $payment = $cart->startPayment(idealType(), is_custom: true);
    $cart->fresh()->reopen();
    $cart->fresh()->add(productPriced(9000), 1);

    event(new PaymentStatusPaid(tap($payment)->forceFill(['paid_amount' => 1000, 'status' => Payment::STATUS_PAID])));

    expect(Order::sole()->total_including_vat)->toBe(1000);
});

it('refuses a paid amount that does not match the snapshot and reports it', function (): void {
    Event::fake([PaymentSnapshotMismatch::class, OrderCreated::class]);
    $cart = checkoutReadyCart(1000);
    $snapshot = $cart->getPayableSnapshot();
    $payment = paymentFor($cart, $snapshot, total: 1000, paid: 900);

    event(new PaymentStatusPaid($payment));

    expect(Order::count())->toBe(0)
        ->and($cart->fresh()->isConverted())->toBeFalse();
    Event::assertDispatched(PaymentSnapshotMismatch::class, fn (PaymentSnapshotMismatch $e): bool => $e->expectedAmount === 1000 && $e->paidAmount === 900 && $e->payment->is($payment));
    Event::assertNotDispatched(OrderCreated::class);
});

it('refuses a tampered snapshot total', function (): void {
    Event::fake([PaymentSnapshotMismatch::class]);
    $cart = checkoutReadyCart(1000);
    $snapshot = $cart->getPayableSnapshot();
    $snapshot['total_amount'] = 5000;
    $payment = paymentFor($cart, $snapshot, total: 1000, paid: 1000);

    event(new PaymentStatusPaid($payment));

    expect(Order::count())->toBe(0);
    Event::assertDispatched(PaymentSnapshotMismatch::class);
});

it('falls back to the live cart for a payment started before snapshots existed', function (): void {
    $cart = checkoutReadyCart(1000);
    $payment = paymentFor($cart, null, total: 1000, paid: 1000);

    event(new PaymentStatusPaid($payment));

    expect(Order::sole()->total_including_vat)->toBe(1000)
        ->and(Order::sole()->payment_id)->toBeNull();
});

it('reports a legacy payment whose amount no longer matches the cart', function (): void {
    Event::fake([PaymentSnapshotMismatch::class]);
    $cart = checkoutReadyCart(1000);
    $payment = paymentFor($cart, null, total: 1000, paid: 1000);
    $cart->add(productPriced(500), 1);

    event(new PaymentStatusPaid($payment));

    expect(Order::count())->toBe(0);
    Event::assertDispatched(PaymentSnapshotMismatch::class, fn (PaymentSnapshotMismatch $e): bool => $e->expectedAmount === 1500 && $e->paidAmount === 1000);
});

it('does nothing when automatic conversion is switched off', function (): void {
    config()->set('cart.payable.convert_on_paid', false);
    $cart = checkoutReadyCart(1000);
    $payment = paymentFor($cart, $cart->getPayableSnapshot(), total: 1000, paid: 1000);

    event(new PaymentStatusPaid($payment));

    expect(Order::count())->toBe(0);
});

it('ignores payments for payables that are not carts', function (): void {
    $type = idealType();
    $payment = Payment::withoutEvents(fn () => Payment::create([
        'id' => (string) Str::uuid(),
        'payable_type' => 'App\\Models\\Invoice',
        'payable_id' => '11111111-1111-4111-8111-111111111111',
        'payment_provider_id' => $type->payment_provider_id,
        'payment_type_id' => $type->id,
        'simple_checkout' => false,
        'total_amount' => 100,
        'paid_amount' => 100,
        'status' => Payment::STATUS_PAID,
        'started' => now(),
        'start_ip' => '127.0.0.1',
    ]));

    event(new PaymentStatusPaid($payment));

    expect(Order::count())->toBe(0);
});

it('ignores a payment whose cart is gone', function (): void {
    $cart = checkoutReadyCart(1000);
    $payment = paymentFor($cart, $cart->getPayableSnapshot(), total: 1000, paid: 1000);
    $cart->forceDelete();

    event(new PaymentStatusPaid($payment->fresh()));

    expect(Order::count())->toBe(0);
});

it('handles a webhook that fires twice by returning the same order', function (): void {
    $cart = checkoutReadyCart(1000);
    $payment = $cart->startPayment(idealType(), is_custom: true);
    $payment->forceFill(['paid_amount' => 1000, 'status' => Payment::STATUS_PAID]);

    event(new PaymentStatusPaid($payment));
    event(new PaymentStatusPaid($payment));

    expect(Order::count())->toBe(1);
});
