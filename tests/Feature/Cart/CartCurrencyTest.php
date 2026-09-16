<?php

declare(strict_types=1);

use Marshmallow\Ecommerce\Cart\Enums\CartItemType;
use Marshmallow\Ecommerce\Cart\Exceptions\CurrencyMismatchException;
use Marshmallow\Ecommerce\Cart\Models\ShippingMethod;
use Marshmallow\Ecommerce\Cart\Models\ShoppingCart;
use Marshmallow\Ecommerce\Cart\Support\Price;

it('refuses a line in another currency than the cart', function (): void {
    $cart = cartOf([[1000, 1, 21.0]]);

    expect(fn () => $cart->addCustom('Dollar item', Price::fromGross(500, 21, 'USD'), CartItemType::Product))
        ->toThrow(CurrencyMismatchException::class, 'Cannot add a USD line to a cart priced in EUR.')
        ->and($cart->fresh()->items)->toHaveCount(1);
});

it('refuses a fee in another currency', function (): void {
    $cart = cartOf([[1000, 1, 21.0]]);

    expect(fn () => $cart->setFee('Toeslag', Price::fromGross(150, 21, 'USD')))
        ->toThrow(CurrencyMismatchException::class);
});

it('refuses a shipping method priced in another currency', function (): void {
    ShippingMethod::factory()->create(['currency' => 'USD']);
    $cart = ShoppingCart::completelyNew();

    expect(fn () => $cart->add(productPriced(1000), 1))->toThrow(CurrencyMismatchException::class);
});

it('accepts any currency on the first line and the same one afterwards', function (): void {
    $cart = ShoppingCart::completelyNew();

    $cart->addCustom('Dollar item', Price::fromGross(1000, 21, 'USD'), CartItemType::Product);
    $cart->fresh()->addCustom('Second dollar item', Price::fromGross(500, 21, 'usd'), CartItemType::Product, meta: ['size' => 'L']);
    $cart->fresh()->setFee('Toeslag', Price::fromGross(150, 21, 'USD'));

    expect($cart->fresh()->items)->toHaveCount(3)
        ->and($cart->fresh()->getTotalAmount())->toBe(1650);
});

it('accepts a new currency once the cart is empty again', function (): void {
    $cart = ShoppingCart::completelyNew();
    $euro = $cart->add(productPriced(1000), 1);
    $euro->delete();

    $line = $cart->fresh()->addCustom('Dollar item', Price::fromGross(1000, 21, 'USD'), CartItemType::Product);

    expect($line->currency)->toBe('USD');
});
