<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Marshmallow\Ecommerce\Cart\Enums\CartItemType;
use Marshmallow\Ecommerce\Cart\Events\DiscountRejected;
use Marshmallow\Ecommerce\Cart\Exceptions\CartLockedException;
use Marshmallow\Ecommerce\Cart\Models\Discount;
use Marshmallow\Ecommerce\Cart\Models\Order;
use Marshmallow\Ecommerce\Cart\Models\ShoppingCart;
use Marshmallow\Ecommerce\Cart\Models\ShoppingCartItem;
use Marshmallow\Ecommerce\Cart\Support\Price;

function lockedCart(): ShoppingCart
{
    $cart = checkoutReadyCart(10000);
    $cart->applyDiscount(Discount::factory()->create(['fixed_amount' => 1000]));
    $cart->fresh()->forceFill(['confirmed_at' => now()])->save();

    return $cart->fresh();
}

it('refuses a discount on a confirmed cart without recording a rejection', function (): void {
    Event::fake([DiscountRejected::class]);
    $cart = lockedCart();

    expect(fn () => $cart->applyDiscount(Discount::factory()->create()))->toThrow(CartLockedException::class);
    Event::assertNotDispatched(DiscountRejected::class);
});

it('refuses to remove a discount from a confirmed cart', function (): void {
    $cart = lockedCart();

    expect(fn () => $cart->removeDiscount())->toThrow(CartLockedException::class)
        ->and($cart->fresh()->discountItems())->toHaveCount(1);
});

it('refuses direct writes to the lines of a confirmed cart', function (): void {
    $cart = lockedCart();
    $line = $cart->productItems()->sole();

    expect(fn () => $line->update(['description' => 'Gewijzigd']))->toThrow(CartLockedException::class)
        ->and(fn () => $line->applyPrice(Price::fromGross(1, 21)))->toThrow(CartLockedException::class)
        ->and(fn () => $line->increaseQuantity())->toThrow(CartLockedException::class)
        ->and(fn () => $line->decreaseQuantity())->toThrow(CartLockedException::class);

    $stray = new ShoppingCartItem([
        'shopping_cart_id' => $cart->id,
        'description' => 'Smokkel',
        'type' => CartItemType::Product,
        'price_including_vat' => 1,
        'price_excluding_vat' => 1,
        'vat_amount' => 0,
        'vat_percentage' => 0,
        'currency' => 'EUR',
    ]);

    expect(fn () => $stray->save())->toThrow(CartLockedException::class)
        ->and($cart->fresh()->items)->toHaveCount(2);
});

it('refuses a custom line on a confirmed cart', function (): void {
    $cart = lockedCart();

    expect(fn () => $cart->addCustom('Extra', Price::fromGross(100, 21), CartItemType::Product))
        ->toThrow(CartLockedException::class);
});

it('still converts a confirmed cart into an order', function (): void {
    $cart = lockedCart();

    $order = $cart->convertToOrder(expectedTotalAmount: 9000);

    expect($order)->toBeInstanceOf(Order::class)
        ->and($order->total_including_vat)->toBe(9000)
        ->and($cart->fresh()->confirmed_at)->not->toBeNull()
        ->and($cart->fresh()->customer_id)->not->toBeNull();
});

it('still reads totals and the payable snapshot from a confirmed cart', function (): void {
    $cart = lockedCart();

    expect($cart->getTotalAmount())->toBe(9000)
        ->and($cart->getPayableSnapshot()['total_amount'])->toBe(9000)
        ->and($cart->getPayableSnapshot()['lines'])->toHaveCount(2);
});

it('does not lock other carts', function (): void {
    lockedCart();
    $open = cartOf([[1000, 1, 21.0]]);

    expect($open->add(productPriced(500), 1)->quantity)->toBe(1);
});
