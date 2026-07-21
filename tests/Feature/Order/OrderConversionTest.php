<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Marshmallow\Ecommerce\Cart\Enums\CartItemType;
use Marshmallow\Ecommerce\Cart\Enums\OrderStatus;
use Marshmallow\Ecommerce\Cart\Events\OrderCreated;
use Marshmallow\Ecommerce\Cart\Exceptions\PurchasableUnavailableException;
use Marshmallow\Ecommerce\Cart\Models\Customer;
use Marshmallow\Ecommerce\Cart\Models\Discount;
use Marshmallow\Ecommerce\Cart\Models\Order;
use Marshmallow\Ecommerce\Cart\Models\Prospect;
use Marshmallow\Ecommerce\Cart\Models\ShippingMethod;
use Marshmallow\Ecommerce\Cart\Models\ShoppingCart;
use Workbench\App\Models\Product;

function paidCart(): ShoppingCart
{
    $cart = ShoppingCart::completelyNew();
    $cart->prospect->update(['first_name' => 'Sam', 'last_name' => 'Jones', 'email' => 'sam@example.com']);
    $cart->add(Product::factory()->create(['price_cents' => 12100, 'vat_percentage' => 21]), 2);

    return $cart->fresh();
}

it('converts a cart into a pending order and fires OrderCreated', function (): void {
    Event::fake([OrderCreated::class]);
    $cart = paidCart();

    $order = $cart->convertToOrder();

    expect($order)->toBeInstanceOf(Order::class)
        ->and($order->status)->toBe(OrderStatus::Pending)
        ->and($order->total_including_vat)->toBe(24200)
        ->and($order->subtotal_including_vat)->toBe(24200)
        ->and($order->items)->toHaveCount(1)
        ->and($order->currency)->toBe('EUR');
    Event::assertDispatched(OrderCreated::class);
});

it('promotes the prospect to a customer without deleting it', function (): void {
    $cart = paidCart();
    $prospectId = $cart->prospect_id;

    $order = $cart->convertToOrder();

    expect($order->customer)->not->toBeNull()
        ->and($order->customer->email)->toBe('sam@example.com')
        ->and(Prospect::find($prospectId))->not->toBeNull()
        ->and(Prospect::find($prospectId)->converted_at)->not->toBeNull();
});

it('copies the actual shipping method onto the order', function (): void {
    ShippingMethod::factory()->create(['name' => 'PostNL', 'price_including_vat' => 495]);
    $cart = paidCart();

    $order = $cart->fresh()->convertToOrder();

    expect($order->shipping_method_id)->toBe($cart->fresh()->shipping_method_id)
        ->and($order->shipping_method_id)->not->toBeNull()
        ->and($order->shipping_including_vat)->toBe(495);
});

it('records discount totals on the order', function (): void {
    $cart = paidCart();
    $cart->applyDiscount(Discount::factory()->create(['fixed_amount' => 2000]));

    $order = $cart->fresh()->convertToOrder();

    expect($order->discount_including_vat)->toBe(-2000)
        ->and($order->total_including_vat)->toBe(22200);
});

it('is idempotent on the cart id', function (): void {
    $cart = paidCart();

    $first = $cart->convertToOrder();
    $second = $cart->fresh()->convertToOrder();

    expect($first->id)->toBe($second->id)
        ->and(Order::count())->toBe(1);
});

it('reuses the cart customer when one is already set', function (): void {
    $customer = Customer::factory()->create();
    $cart = paidCart();
    $cart->update(['customer_id' => $customer->id]);

    $order = $cart->fresh()->convertToOrder();

    expect($order->customer_id)->toBe($customer->id);
});

it('refuses to convert when a line is no longer available', function (): void {
    $product = Product::factory()->create(['price_cents' => 1000]);
    $cart = ShoppingCart::completelyNew();
    $cart->add($product, 1);
    $product->update(['stock' => 0]);

    expect(fn () => $cart->fresh()->convertToOrder())->toThrow(PurchasableUnavailableException::class);
});

it('skips the availability check at checkout when disabled', function (): void {
    config()->set('cart.stock.check_on_checkout', false);
    $product = Product::factory()->create(['price_cents' => 1000]);
    $cart = ShoppingCart::completelyNew();
    $cart->add($product, 1);
    $product->update(['stock' => 0]);

    expect($cart->fresh()->convertToOrder())->toBeInstanceOf(Order::class);
});

it('copies order item snapshots including type', function (): void {
    $order = paidCart()->convertToOrder();

    expect($order->items->first()->type)->toBe(CartItemType::Product)
        ->and($order->items->first()->getTotalAmount())->toBe(24200);
});

it('transitions through order statuses', function (): void {
    $order = paidCart()->convertToOrder();

    $order->markAsCompleted();
    expect($order->isCompleted())->toBeTrue();

    $order->markAsCanceled();
    expect($order->isCanceled())->toBeTrue();

    $order->markAsPending();
    expect($order->isPending())->toBeTrue()
        ->and(Order::pending()->count())->toBe(1)
        ->and(Order::completed()->count())->toBe(0)
        ->and(Order::canceled()->count())->toBe(0);
});
