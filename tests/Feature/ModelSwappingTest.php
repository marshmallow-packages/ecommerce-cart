<?php

declare(strict_types=1);

use Marshmallow\Ecommerce\Cart\Exceptions\DiscountException;
use Marshmallow\Ecommerce\Cart\Facades\Cart;
use Marshmallow\Ecommerce\Cart\Models\Discount;
use Marshmallow\Ecommerce\Cart\Models\ShippingMethod;
use Marshmallow\Ecommerce\Cart\Tests\Fixtures\TenantOrder;
use Marshmallow\Ecommerce\Cart\Tests\Fixtures\TenantOrderItem;
use Marshmallow\Ecommerce\Cart\Tests\Fixtures\TenantShoppingCart;
use Marshmallow\Ecommerce\Cart\Tests\Fixtures\TenantShoppingCartItem;

beforeEach(function (): void {
    config()->set('cart.models.shopping_cart', TenantShoppingCart::class);
    config()->set('cart.models.shopping_cart_item', TenantShoppingCartItem::class);
    config()->set('cart.models.order', TenantOrder::class);
    config()->set('cart.models.order_item', TenantOrderItem::class);
});

it('resolves the configured cart subclass from the session and the middleware', function (): void {
    $cart = Cart::get();

    expect($cart)->toBeInstanceOf(TenantShoppingCart::class)
        ->and($cart->tenantLabel())->toBe('tenant:'.$cart->display_id)
        ->and(TenantShoppingCart::getBySession())->toBeInstanceOf(TenantShoppingCart::class);
});

it('creates lines through the configured item subclass', function (): void {
    $cart = Cart::get();

    $item = $cart->add(productPriced(1000), 1);

    expect($item)->toBeInstanceOf(TenantShoppingCartItem::class)
        ->and($cart->fresh()->items->first())->toBeInstanceOf(TenantShoppingCartItem::class)
        ->and($item->cart)->toBeInstanceOf(TenantShoppingCart::class);
});

it('derives shipping and discount lines through the configured item subclass', function (): void {
    ShippingMethod::factory()->create();
    $cart = Cart::get();
    $cart->add(productPriced(10000), 1);
    $cart->fresh()->applyDiscount(Discount::factory()->create(['fixed_amount' => 100]));

    $cart = $cart->fresh();
    expect($cart->getShippingItem())->toBeInstanceOf(TenantShoppingCartItem::class)
        ->and($cart->discountItems()->sole())->toBeInstanceOf(TenantShoppingCartItem::class);
});

it('converts into the configured order and order item subclasses', function (): void {
    $cart = Cart::get();
    $cart->prospect->update(['email' => 'tenant@example.com']);
    $cart->add(productPriced(1000), 1);

    $order = $cart->fresh()->convertToOrder();

    expect($order)->toBeInstanceOf(TenantOrder::class)
        ->and($order->items->first())->toBeInstanceOf(TenantOrderItem::class)
        ->and($order->cart)->toBeInstanceOf(TenantShoppingCart::class)
        ->and($cart->fresh()->convertToOrder())->toBeInstanceOf(TenantOrder::class);
});

it('counts discount usage through the configured order item subclass', function (): void {
    $discount = Discount::factory()->create(['discount_code' => 'ONCE', 'total_usage_limit' => 1, 'fixed_amount' => 100]);
    $cart = Cart::get();
    $cart->prospect->update(['email' => 'tenant@example.com']);
    $cart->add(productPriced(1000), 1);
    $cart->fresh()->applyDiscount($discount);
    $cart->fresh()->convertToOrder();

    session()->forget(TenantShoppingCart::SESSION_KEY);
    $second = Cart::get();
    $second->add(productPriced(1000), 1);

    expect(fn () => $second->fresh()->applyDiscount($discount->fresh()))
        ->toThrow(DiscountException::class);
});

it('reorders into the configured cart subclass', function (): void {
    $cart = Cart::get();
    $cart->prospect->update(['email' => 'tenant@example.com']);
    $cart->add(productPriced(1000), 1);
    $order = $cart->fresh()->convertToOrder();
    session()->forget(TenantShoppingCart::SESSION_KEY);

    expect($order->toNewCart())->toBeInstanceOf(TenantShoppingCart::class);
});
