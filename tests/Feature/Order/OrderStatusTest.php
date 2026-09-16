<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Marshmallow\Ecommerce\Cart\Enums\OrderStatus;
use Marshmallow\Ecommerce\Cart\Events\OrderRefunded;
use Marshmallow\Ecommerce\Cart\Exceptions\InvalidOrderStatusTransitionException;
use Marshmallow\Ecommerce\Cart\Models\Order;

function orderIn(OrderStatus $status): Order
{
    $order = checkoutReadyCart()->convertToOrder();
    $order->forceFill(['status' => $status])->saveQuietly();

    return $order->fresh();
}

it('allows the documented transitions', function (OrderStatus $from, OrderStatus $to): void {
    expect($from->canTransitionTo($to))->toBeTrue();
})->with([
    'pending to canceled' => [OrderStatus::Pending, OrderStatus::Canceled],
    'pending to completed' => [OrderStatus::Pending, OrderStatus::Completed],
    'pending to refunded' => [OrderStatus::Pending, OrderStatus::Refunded],
    'completed to refunded' => [OrderStatus::Completed, OrderStatus::Refunded],
    'canceled back to pending' => [OrderStatus::Canceled, OrderStatus::Pending],
]);

it('rules out the other transitions', function (OrderStatus $from, OrderStatus $to): void {
    expect($from->canTransitionTo($to))->toBeFalse();
})->with([
    'completed to pending' => [OrderStatus::Completed, OrderStatus::Pending],
    'completed to canceled' => [OrderStatus::Completed, OrderStatus::Canceled],
    'canceled to completed' => [OrderStatus::Canceled, OrderStatus::Completed],
    'canceled to refunded' => [OrderStatus::Canceled, OrderStatus::Refunded],
    'refunded to pending' => [OrderStatus::Refunded, OrderStatus::Pending],
    'refunded to completed' => [OrderStatus::Refunded, OrderStatus::Completed],
    'refunded to canceled' => [OrderStatus::Refunded, OrderStatus::Canceled],
]);

it('throws and keeps the status on a forbidden transition', function (): void {
    $order = orderIn(OrderStatus::Completed);

    expect(fn () => $order->markAsCanceled())
        ->toThrow(InvalidOrderStatusTransitionException::class, 'cannot move from COMPLETED to CANCELED')
        ->and($order->fresh()->status)->toBe(OrderStatus::Completed);
});

it('does not let a canceled order be completed or refunded', function (): void {
    $order = orderIn(OrderStatus::Canceled);

    expect(fn () => $order->markAsCompleted())->toThrow(InvalidOrderStatusTransitionException::class)
        ->and(fn () => $order->markAsRefunded())->toThrow(InvalidOrderStatusTransitionException::class);
});

it('refunds a completed order', function (): void {
    Event::fake([OrderRefunded::class]);
    $order = orderIn(OrderStatus::Completed);

    $order->markAsRefunded();

    expect($order->fresh()->isRefunded())->toBeTrue();
    Event::assertDispatched(OrderRefunded::class);
});

it('announces a refund once, even when marked twice', function (): void {
    Event::fake([OrderRefunded::class]);
    $order = orderIn(OrderStatus::Pending);

    $order->markAsRefunded();
    $order->markAsRefunded();

    expect($order->fresh()->isRefunded())->toBeTrue();
    Event::assertDispatchedTimes(OrderRefunded::class, 1);
});

it('treats marking the current status again as a no-op', function (): void {
    $order = orderIn(OrderStatus::Pending);
    $order->markAsPending();

    $done = orderIn(OrderStatus::Completed);
    $done->markAsCompleted();

    expect($order->fresh()->isPending())->toBeTrue()
        ->and($done->fresh()->isCompleted())->toBeTrue();
});
