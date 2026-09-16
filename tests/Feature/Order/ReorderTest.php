<?php

declare(strict_types=1);

use Marshmallow\Ecommerce\Cart\Models\Order;
use Marshmallow\Ecommerce\Cart\Models\ShoppingCart;
use Workbench\App\Models\Product;

/**
 * An order with three product lines: A (2x, meta), B (1x) and C (3x).
 *
 * @return array{0: Order, 1: Product, 2: Product, 3: Product}
 */
function threeLineOrder(): array
{
    $a = productPriced(1000);
    $b = productPriced(2000);
    $c = productPriced(3000);
    $cart = ShoppingCart::completelyNew();
    $cart->prospect->update(['email' => 'reorder@example.com']);
    $cart->add($a, 2, ['size' => 'M']);
    $cart->add($b, 1);
    $cart->add($c, 3);
    $order = $cart->fresh()->convertToOrder();
    session()->forget(ShoppingCart::SESSION_KEY);

    return [$order, $a, $b, $c];
}

it('rebuilds every line with its quantity and meta', function (): void {
    [$order, $a, $b, $c] = threeLineOrder();

    $cart = $order->toNewCart();
    $lines = $cart->fresh()->productItems();

    expect($lines)->toHaveCount(3)
        ->and($lines->firstWhere('purchasable_id', (string) $a->id)->quantity)->toBe(2)
        ->and($lines->firstWhere('purchasable_id', (string) $a->id)->meta)->toBe(['size' => 'M'])
        ->and($lines->firstWhere('purchasable_id', (string) $c->id)->quantity)->toBe(3)
        ->and($cart->getSubtotal())->toBe(2000 + 2000 + 9000);
});

it('skips a sold-out line but keeps the lines after it', function (): void {
    [$order, $a, $b, $c] = threeLineOrder();
    $a->update(['stock' => 1]); // 2 were ordered

    $lines = $order->toNewCart()->fresh()->productItems();

    expect($lines->pluck('purchasable_id')->map(fn ($id) => (int) $id)->sort()->values()->all())->toBe([$b->id, $c->id]);
});

it('skips a vanished product but keeps the lines after it', function (): void {
    [$order, $a, $b, $c] = threeLineOrder();
    $b->delete();

    $lines = $order->toNewCart()->fresh()->productItems();

    expect($lines->pluck('purchasable_id')->map(fn ($id) => (int) $id)->sort()->values()->all())->toBe([$a->id, $c->id]);
});

it('makes the new cart the session cart', function (): void {
    [$order] = threeLineOrder();

    $cart = $order->toNewCart();

    expect(session()->get(ShoppingCart::SESSION_KEY))->toBe($cart->id)
        ->and(ShoppingCart::getBySession()->is($cart))->toBeTrue()
        ->and($cart->authorized())->toBeTrue();
});

it('starts the reorder as an unconfirmed cart on the same prospect chain', function (): void {
    [$order] = threeLineOrder();

    $cart = $order->toNewCart();

    expect($cart->isOpen())->toBeTrue()
        ->and($cart->is($order->cart))->toBeFalse()
        ->and($cart->discountItems())->toHaveCount(0);
});
