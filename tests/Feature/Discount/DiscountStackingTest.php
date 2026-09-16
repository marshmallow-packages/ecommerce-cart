<?php

declare(strict_types=1);

use Marshmallow\Ecommerce\Cart\Enums\DiscountPrerequisite;
use Marshmallow\Ecommerce\Cart\Exceptions\DiscountException;
use Marshmallow\Ecommerce\Cart\Models\Discount;
use Marshmallow\Ecommerce\Cart\Models\ShoppingCart;
use Workbench\App\Models\Product;

function stackCart(int $priceCents = 10000): ShoppingCart
{
    $cart = ShoppingCart::completelyNew();
    $cart->add(Product::factory()->create(['price_cents' => $priceCents, 'vat_percentage' => 21]), 1);

    return $cart->refresh();
}

it('stacks two combinable codes', function (): void {
    $cart = stackCart(10000);
    $cart->applyDiscount(Discount::factory()->create(['discount_code' => 'EEN', 'fixed_amount' => 1000, 'is_combinable' => true]));
    $cart->refresh()->applyDiscount(Discount::factory()->create(['discount_code' => 'TWEE', 'fixed_amount' => 500, 'is_combinable' => true]));

    $cart = $cart->refresh();
    expect($cart->discountItems())->toHaveCount(2)
        ->and($cart->getDiscountAmount())->toBe(-1500)
        ->and($cart->getTotalAmount())->toBe(8500);
});

it('lets a non-combinable code replace whatever was applied', function (): void {
    $cart = stackCart(10000);
    $cart->applyDiscount(Discount::factory()->create(['discount_code' => 'EEN', 'fixed_amount' => 1000, 'is_combinable' => true]));
    $cart->refresh()->applyDiscount(Discount::factory()->create(['discount_code' => 'SOLO', 'fixed_amount' => 2000, 'is_combinable' => false]));

    $cart = $cart->refresh();
    expect($cart->discountItems())->toHaveCount(1)
        ->and($cart->discountItems()->sole()->description)->toBe('SOLO')
        ->and($cart->getDiscountAmount())->toBe(-2000);
});

it('replaces a non-combinable code even by a combinable newcomer', function (): void {
    $cart = stackCart(10000);
    $cart->applyDiscount(Discount::factory()->create(['discount_code' => 'SOLO', 'fixed_amount' => 2000, 'is_combinable' => false]));
    $cart->refresh()->applyDiscount(Discount::factory()->create(['discount_code' => 'SAMEN', 'fixed_amount' => 500, 'is_combinable' => true]));

    $cart = $cart->refresh();
    expect($cart->discountItems()->sole()->description)->toBe('SAMEN');
});

it('refuses the same code twice', function (): void {
    $cart = stackCart(10000);
    $discount = Discount::factory()->create(['discount_code' => 'EEN', 'fixed_amount' => 1000, 'is_combinable' => true]);
    $cart->applyDiscount($discount);

    expect(fn () => $cart->refresh()->applyDiscount($discount->refresh()))->toThrow(DiscountException::class);
});

it('compounds a stacked percentage over the discounted subtotal', function (): void {
    $cart = stackCart(10000);
    $cart->applyDiscount(Discount::factory()->create(['discount_code' => 'VAST', 'fixed_amount' => 2000, 'is_combinable' => true]));
    $cart->refresh()->applyDiscount(Discount::factory()->percentage(10)->create(['discount_code' => 'PROC', 'is_combinable' => true]));

    // 10% over (10000 - 2000) = 800.
    expect($cart->refresh()->getDiscountAmount())->toBe(-2800);
});

it('never lets stacked codes push the products below zero', function (): void {
    $cart = stackCart(1000);
    $cart->applyDiscount(Discount::factory()->create(['discount_code' => 'A', 'fixed_amount' => 800, 'is_combinable' => true]));
    $cart->refresh()->applyDiscount(Discount::factory()->create(['discount_code' => 'B', 'fixed_amount' => 800, 'is_combinable' => true]));

    expect($cart->refresh()->getDiscountAmount())->toBe(-1000);
});

it('removes one code by name and keeps the rest', function (): void {
    $cart = stackCart(10000);
    $cart->applyDiscount(Discount::factory()->create(['discount_code' => 'EEN', 'fixed_amount' => 1000, 'is_combinable' => true]));
    $cart->refresh()->applyDiscount(Discount::factory()->create(['discount_code' => 'TWEE', 'fixed_amount' => 500, 'is_combinable' => true]));

    $cart->refresh()->removeDiscount('EEN');

    $cart = $cart->refresh();
    expect($cart->discountItems())->toHaveCount(1)
        ->and($cart->discountItems()->sole()->description)->toBe('TWEE');
});

it('reapplies every stacked code when the cart changes', function (): void {
    $product = Product::factory()->create(['price_cents' => 10000, 'vat_percentage' => 21]);
    $cart = ShoppingCart::completelyNew();
    $cart->add($product, 1);
    $cart = $cart->refresh();
    $cart->applyDiscount(Discount::factory()->create(['discount_code' => 'VAST', 'fixed_amount' => 1000, 'is_combinable' => true]));
    $cart->refresh()->applyDiscount(Discount::factory()->percentage(10)->create(['discount_code' => 'PROC', 'is_combinable' => true]));

    $cart->refresh()->add($product, 1); // subtotal 20000 -> VAST 1000, PROC 10% over 19000

    expect($cart->refresh()->getDiscountAmount())->toBe(-2900);
});

it('drops a code that no longer qualifies after a cart change', function (): void {
    $product = Product::factory()->create(['price_cents' => 10000, 'vat_percentage' => 21]);
    $cart = ShoppingCart::completelyNew();
    $item = $cart->add($product, 2);
    $cart = $cart->refresh();
    $cart->applyDiscount(Discount::factory()->create([
        'discount_code' => 'DREMPEL',
        'fixed_amount' => 1000,
        'prerequisite_type' => DiscountPrerequisite::PurchaseAmount,
        'prerequisite_purchase_amount' => 15000,
    ]));

    $item->refresh()->setQuantity(1); // subtotal drops below the threshold

    expect($cart->refresh()->discountItems())->toHaveCount(0);
});
