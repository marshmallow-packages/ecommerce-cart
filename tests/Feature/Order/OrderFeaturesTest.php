<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Marshmallow\Ecommerce\Cart\Enums\CartItemType;
use Marshmallow\Ecommerce\Cart\Enums\OrderStatus;
use Marshmallow\Ecommerce\Cart\Events\OrderRefunded;
use Marshmallow\Ecommerce\Cart\Models\Order;
use Marshmallow\Ecommerce\Cart\Models\ShoppingCart;
use Marshmallow\Ecommerce\Cart\Support\Price;
use Workbench\App\Models\Product;

function featureOrder(): Order
{
    $cart = ShoppingCart::completelyNew();
    $cart->prospect?->update(['first_name' => 'Sam', 'email' => 'sam@example.com']);
    $cart->add(Product::factory()->create(['price_cents' => 1000]), 2);

    $order = $cart->refresh()->convertToOrder();
    session()->forget(ShoppingCart::SESSION_KEY);

    return $order;
}

it('marks an order as refunded and fires OrderRefunded', function (): void {
    Event::fake([OrderRefunded::class]);
    $order = featureOrder();

    $order->markAsRefunded();

    expect($order->isRefunded())->toBeTrue()
        ->and($order->status)->toBe(OrderStatus::Refunded)
        ->and(Order::refunded()->count())->toBe(1);
    Event::assertDispatched(OrderRefunded::class, fn (OrderRefunded $e): bool => $e->order->is($order));
});

it('reorders into a fresh cart at current prices', function (): void {
    $order = featureOrder();
    $product = Product::query()->sole();
    $product->update(['price_cents' => 1250]); // price moved since the order

    $cart = $order->toNewCart();

    expect($cart->is($order->cart))->toBeFalse()
        ->and($cart->productItems())->toHaveCount(1)
        ->and($cart->productItems()->sole()->quantity)->toBe(2)
        ->and($cart->productItems()->sole()->price_including_vat)->toBe(1250);
});

it('leaves vanished or unavailable products off the reorder', function (): void {
    $order = featureOrder();
    Product::query()->sole()->update(['stock' => 0]);

    $cart = $order->toNewCart();

    expect($cart->productItems())->toHaveCount(0);
});

it('skips non-product lines when reordering', function (): void {
    $cart = ShoppingCart::completelyNew();
    $cart->prospect?->update(['email' => 'sam@example.com']);
    $cart->add(Product::factory()->create(['price_cents' => 1000]), 1);
    $cart->refresh()->addCustom('Toeslag', Price::fromGross(150, 21), CartItemType::Fee);
    $order = $cart->refresh()->convertToOrder();
    session()->forget(ShoppingCart::SESSION_KEY);

    $reorder = $order->toNewCart();

    expect($reorder->productItems())->toHaveCount(1)
        ->and($reorder->feeItems())->toHaveCount(0);
});
