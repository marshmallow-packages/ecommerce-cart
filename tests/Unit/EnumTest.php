<?php

declare(strict_types=1);

use Marshmallow\Ecommerce\Cart\Enums\CartItemType;
use Marshmallow\Ecommerce\Cart\Enums\DiscountAppliesTo;
use Marshmallow\Ecommerce\Cart\Enums\DiscountEligibility;
use Marshmallow\Ecommerce\Cart\Enums\DiscountPrerequisite;
use Marshmallow\Ecommerce\Cart\Enums\DiscountType;
use Marshmallow\Ecommerce\Cart\Enums\OrderStatus;

it('reports the cart item type', function (): void {
    expect(CartItemType::Product->isProduct())->toBeTrue()
        ->and(CartItemType::Product->isShipping())->toBeFalse()
        ->and(CartItemType::Product->isDiscount())->toBeFalse()
        ->and(CartItemType::Product->isFee())->toBeFalse()
        ->and(CartItemType::Shipping->isShipping())->toBeTrue()
        ->and(CartItemType::Discount->isDiscount())->toBeTrue()
        ->and(CartItemType::Fee->isFee())->toBeTrue();
});

/*
 * The backing values are persisted in the database and matched in raw queries
 * (e.g. the discount usage count filters on 'DISCOUNT'). Renaming one silently
 * breaks existing rows, so the exact strings are pinned here.
 */
it('pins the persisted backing values of every enum', function (string $enum, array $values): void {
    expect(array_column($enum::cases(), 'value'))->toBe($values);
})->with([
    'cart item types' => [CartItemType::class, ['PRODUCT', 'DISCOUNT', 'SHIPPING', 'FEE']],
    'order statuses' => [OrderStatus::class, ['PENDING', 'CANCELED', 'COMPLETED', 'REFUNDED']],
    'discount types' => [DiscountType::class, ['fixed_amount', 'percentage', 'free_shipping']],
    'discount applies to' => [DiscountAppliesTo::class, ['all', 'specific_categories', 'specific_products']],
    'discount eligibility' => [DiscountEligibility::class, ['all', 'eligible_for_customers', 'eligible_for_emails']],
    'discount prerequisites' => [DiscountPrerequisite::class, ['none', 'prerequisite_purchase_amount', 'prerequisite_quantity']],
]);

it('round-trips an enum through its backing value', function (): void {
    expect(CartItemType::from('SHIPPING'))->toBe(CartItemType::Shipping)
        ->and(OrderStatus::tryFrom('SHIPPED'))->toBeNull()
        ->and(DiscountType::from('percentage'))->toBe(DiscountType::Percentage);
});
