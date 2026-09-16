<?php

declare(strict_types=1);

use Marshmallow\Ecommerce\Cart\Exceptions\CartException;
use Marshmallow\Ecommerce\Cart\Exceptions\CartLockedException;
use Marshmallow\Ecommerce\Cart\Exceptions\DiscountException;
use Marshmallow\Ecommerce\Cart\Exceptions\PaymentAmountMismatchException;
use Marshmallow\Ecommerce\Cart\Exceptions\PurchasableUnavailableException;
use Workbench\App\Models\Product;

it('lets a host catch every cart failure through the base exception', function (): void {
    expect(CartLockedException::make())->toBeInstanceOf(CartException::class)
        ->and(new DiscountException('nope'))->toBeInstanceOf(CartException::class)
        ->and(PaymentAmountMismatchException::make(1, 2))->toBeInstanceOf(CartException::class)
        ->and(PurchasableUnavailableException::for(new Product(['name' => 'X']), 1))->toBeInstanceOf(CartException::class);
});

it('explains how to reopen a locked cart', function (): void {
    expect(CartLockedException::make()->getMessage())
        ->toContain('confirmed for payment')
        ->toContain('confirmed_at');
});

it('names both amounts on a payment mismatch', function (): void {
    $exception = PaymentAmountMismatchException::make(expected: 12100, actual: 12000);

    expect($exception->getMessage())
        ->toContain('12100')
        ->toContain('12000')
        ->toContain('refusing to create the order');
});

it('names the purchasable and quantity that is unavailable', function (): void {
    $product = new Product(['name' => 'Blauwe trui']);

    expect(PurchasableUnavailableException::for($product, 3)->getMessage())
        ->toBe('"Blauwe trui" is not available in the requested quantity (3).');
});
