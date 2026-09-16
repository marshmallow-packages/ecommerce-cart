<?php

declare(strict_types=1);

use Marshmallow\Ecommerce\Cart\Enums\CartItemType;

it('reports the cart item type', function (): void {
    expect(CartItemType::Product->isProduct())->toBeTrue()
        ->and(CartItemType::Product->isShipping())->toBeFalse()
        ->and(CartItemType::Product->isDiscount())->toBeFalse()
        ->and(CartItemType::Product->isFee())->toBeFalse()
        ->and(CartItemType::Shipping->isShipping())->toBeTrue()
        ->and(CartItemType::Discount->isDiscount())->toBeTrue()
        ->and(CartItemType::Fee->isFee())->toBeTrue();
});
