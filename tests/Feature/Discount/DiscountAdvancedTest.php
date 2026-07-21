<?php

declare(strict_types=1);

use Marshmallow\Ecommerce\Cart\Enums\DiscountAppliesTo;
use Marshmallow\Ecommerce\Cart\Enums\DiscountEligibility;
use Marshmallow\Ecommerce\Cart\Exceptions\DiscountException;
use Marshmallow\Ecommerce\Cart\Models\Customer;
use Marshmallow\Ecommerce\Cart\Models\Discount;
use Marshmallow\Ecommerce\Cart\Models\ShoppingCart;
use Workbench\App\Models\Product;

it('limits a discount to products in an eligible category', function (): void {
    $inCategory = Product::factory()->create(['price_cents' => 10000, 'category_ids' => [7]]);
    $cart = ShoppingCart::completelyNew();
    $cart->add($inCategory, 1);

    $discount = Discount::factory()->percentage(10)->create([
        'applies_to' => DiscountAppliesTo::Categories,
        'applies_to_product_categories' => [7],
    ]);

    $cart->fresh()->applyDiscount($discount);

    expect($cart->fresh()->getDiscountAmount())->toBe(-1000);
});

it('rejects a category discount when no line matches the category', function (): void {
    $cart = ShoppingCart::completelyNew();
    $cart->add(Product::factory()->create(['price_cents' => 10000, 'category_ids' => [3]]), 1);

    $discount = Discount::factory()->create([
        'applies_to' => DiscountAppliesTo::Categories,
        'applies_to_product_categories' => [7],
    ]);

    expect(fn () => $cart->fresh()->applyDiscount($discount))->toThrow(DiscountException::class);
});

it('allows a discount for an eligible customer', function (): void {
    $customer = Customer::factory()->create();
    $cart = ShoppingCart::completelyNew();
    $cart->update(['customer_id' => $customer->id]);
    $cart->add(Product::factory()->create(['price_cents' => 10000]), 1);

    $discount = Discount::factory()->create([
        'eligible_for' => DiscountEligibility::Customers,
        'eligible_for_customers' => [$customer->id],
        'fixed_amount' => 1000,
    ]);

    $cart->fresh()->applyDiscount($discount);

    expect($cart->fresh()->getDiscountAmount())->toBe(-1000);
});

it('allows a discount for an eligible email', function (): void {
    $cart = ShoppingCart::completelyNew();
    $cart->prospect->update(['email' => 'vip@example.com']);
    $cart->add(Product::factory()->create(['price_cents' => 10000]), 1);

    $discount = Discount::factory()->create([
        'eligible_for' => DiscountEligibility::Emails,
        'eligible_for_emails' => ['vip@example.com'],
        'fixed_amount' => 1000,
    ]);

    $cart->fresh()->applyDiscount($discount);

    expect($cart->fresh()->getDiscountAmount())->toBe(-1000);
});

it('blocks a discount past its total usage limit', function (): void {
    $discount = Discount::factory()->create(['discount_code' => 'ONCE', 'total_usage_limit' => 1, 'fixed_amount' => 1000]);

    // First redemption becomes an order line.
    $firstCart = ShoppingCart::completelyNew();
    $firstCart->prospect->update(['email' => 'a@example.com']);
    $firstCart->add(Product::factory()->create(['price_cents' => 10000]), 1);
    $firstCart->fresh()->applyDiscount($discount);
    $firstCart->fresh()->convertToOrder();

    $secondCart = ShoppingCart::completelyNew();
    $secondCart->add(Product::factory()->create(['price_cents' => 10000]), 1);

    expect(fn () => $secondCart->fresh()->applyDiscount($discount))->toThrow(DiscountException::class);
});

it('blocks a once-per-customer discount for a returning email', function (): void {
    $discount = Discount::factory()->create(['discount_code' => 'SOLO', 'is_once_per_customer' => true, 'fixed_amount' => 1000]);

    $firstCart = ShoppingCart::completelyNew();
    $firstCart->prospect->update(['first_name' => 'Repeat', 'email' => 'repeat@example.com']);
    $firstCart->add(Product::factory()->create(['price_cents' => 10000]), 1);
    $firstCart->fresh()->applyDiscount($discount);
    $firstCart->fresh()->convertToOrder();

    $secondCart = ShoppingCart::completelyNew();
    $secondCart->prospect->update(['email' => 'repeat@example.com']);
    $secondCart->add(Product::factory()->create(['price_cents' => 10000]), 1);

    expect(fn () => $secondCart->fresh()->applyDiscount($discount))->toThrow(DiscountException::class);
});

it('falls back to the default vat percentage when the cart mixes rates', function (): void {
    config()->set('cart.default_vat_percentage', 21.0);
    $cart = ShoppingCart::completelyNew();
    $cart->add(Product::factory()->create(['price_cents' => 10000, 'vat_percentage' => 21]), 1);
    $cart->add(Product::factory()->create(['price_cents' => 9000, 'vat_percentage' => 9]), 1);

    $discount = Discount::factory()->create(['fixed_amount' => 1000]);
    $cart->fresh()->applyDiscount($discount);

    $line = $cart->fresh()->discountItems()->first();
    expect((float) $line->vat_percentage)->toBe(21.0);
});
