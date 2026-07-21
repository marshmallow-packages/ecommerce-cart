<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Marshmallow\Ecommerce\Cart\Enums\DiscountAppliesTo;
use Marshmallow\Ecommerce\Cart\Enums\DiscountEligibility;
use Marshmallow\Ecommerce\Cart\Enums\DiscountPrerequisite;
use Marshmallow\Ecommerce\Cart\Events\DiscountApplied;
use Marshmallow\Ecommerce\Cart\Events\DiscountRejected;
use Marshmallow\Ecommerce\Cart\Exceptions\DiscountException;
use Marshmallow\Ecommerce\Cart\Models\Discount;
use Marshmallow\Ecommerce\Cart\Models\ShippingMethod;
use Marshmallow\Ecommerce\Cart\Models\ShoppingCart;
use Workbench\App\Models\Product;

function cartWith(int $priceCents, int $qty = 1): ShoppingCart
{
    $cart = ShoppingCart::completelyNew();
    $cart->add(Product::factory()->create(['price_cents' => $priceCents, 'vat_percentage' => 21]), $qty);

    return $cart->fresh();
}

it('applies a fixed amount discount as a negative line', function (): void {
    Event::fake([DiscountApplied::class]);
    $cart = cartWith(10000);
    $discount = Discount::factory()->create(['fixed_amount' => 2500]);

    $cart->applyDiscount($discount);
    $cart = $cart->fresh();

    expect($cart->getDiscountAmount())->toBe(-2500)
        ->and($cart->getTotalAmount())->toBe(7500);
    Event::assertDispatched(DiscountApplied::class);
});

it('caps a fixed discount at the cart subtotal', function (): void {
    $cart = cartWith(1000);
    $discount = Discount::factory()->create(['fixed_amount' => 5000]);

    $cart->applyDiscount($discount);

    expect($cart->fresh()->getDiscountAmount())->toBe(-1000);
});

it('applies a percentage discount', function (): void {
    $cart = cartWith(10000);
    $discount = Discount::factory()->percentage(10)->create();

    $cart->applyDiscount($discount);

    expect($cart->fresh()->getDiscountAmount())->toBe(-1000);
});

it('applies a free shipping discount equal to the shipping cost', function (): void {
    ShippingMethod::factory()->create(['price_including_vat' => 495]);
    $cart = cartWith(10000);
    $discount = Discount::factory()->freeShipping()->create();

    $cart->applyDiscount($discount);

    expect($cart->fresh()->getDiscountAmount())->toBe(-495);
});

it('rejects an inactive discount', function (): void {
    Event::fake([DiscountRejected::class]);
    $cart = cartWith(10000);
    $discount = Discount::factory()->create(['is_active' => false]);

    expect(fn () => $cart->applyDiscount($discount))->toThrow(DiscountException::class);
    Event::assertDispatched(DiscountRejected::class);
});

it('rejects a discount that has not started', function (): void {
    $cart = cartWith(10000);
    $discount = Discount::factory()->create(['starts_at' => now()->addDay()]);

    expect(fn () => $cart->applyDiscount($discount))->toThrow(DiscountException::class);
});

it('rejects a discount that has ended', function (): void {
    $cart = cartWith(10000);
    $discount = Discount::factory()->create(['ends_at' => now()->subDay()]);

    expect(fn () => $cart->applyDiscount($discount))->toThrow(DiscountException::class);
});

it('rejects a discount when no items are eligible', function (): void {
    $cart = cartWith(10000);
    $discount = Discount::factory()->create([
        'applies_to' => DiscountAppliesTo::Products,
        'applies_to_products' => [999999],
    ]);

    expect(fn () => $cart->applyDiscount($discount))->toThrow(DiscountException::class);
});

it('enforces a minimum purchase amount prerequisite', function (): void {
    $cart = cartWith(1000);
    $discount = Discount::factory()->create([
        'prerequisite_type' => DiscountPrerequisite::PurchaseAmount,
        'prerequisite_purchase_amount' => 5000,
    ]);

    expect(fn () => $cart->applyDiscount($discount))->toThrow(DiscountException::class);
});

it('enforces a minimum quantity prerequisite', function (): void {
    $cart = cartWith(1000, 1);
    $discount = Discount::factory()->create([
        'prerequisite_type' => DiscountPrerequisite::Quantity,
        'prerequisite_quantity' => 3,
    ]);

    expect(fn () => $cart->applyDiscount($discount))->toThrow(DiscountException::class);
});

it('restricts a discount to eligible customers', function (): void {
    $cart = cartWith(10000);
    $discount = Discount::factory()->create([
        'eligible_for' => DiscountEligibility::Customers,
        'eligible_for_customers' => [999999],
    ]);

    expect(fn () => $cart->applyDiscount($discount))->toThrow(DiscountException::class);
});

it('restricts a discount to eligible emails', function (): void {
    $cart = cartWith(10000);
    $cart->prospect->update(['email' => 'nope@example.com']);
    $discount = Discount::factory()->create([
        'eligible_for' => DiscountEligibility::Emails,
        'eligible_for_emails' => ['yes@example.com'],
    ]);

    expect(fn () => $cart->applyDiscount($discount->fresh()))->toThrow(DiscountException::class);
});

it('removes a discount', function (): void {
    $cart = cartWith(10000);
    $discount = Discount::factory()->create(['fixed_amount' => 2500]);
    $cart->applyDiscount($discount);

    $cart->fresh()->removeDiscount();

    expect($cart->fresh()->getDiscountAmount())->toBe(0);
});

it('finds a discount by its code, or null', function (): void {
    Discount::factory()->create(['discount_code' => 'HELLO10']);

    expect(Discount::byCode('HELLO10'))->not->toBeNull()
        ->and(Discount::byCode('MISSING'))->toBeNull();
});

it('recalculates a percentage discount when the cart changes', function (): void {
    $product = Product::factory()->create(['price_cents' => 10000, 'vat_percentage' => 21]);
    $cart = ShoppingCart::completelyNew();
    $cart->add($product, 1);
    $cart = $cart->fresh();
    $cart->applyDiscount(Discount::factory()->percentage(10)->create());

    $cart->fresh()->add($product, 1);

    expect($cart->fresh()->getDiscountAmount())->toBe(-2000);
});
