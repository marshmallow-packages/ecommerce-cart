<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Marshmallow\Ecommerce\Cart\Events\DiscountApplied;
use Marshmallow\Ecommerce\Cart\Models\Discount;
use Marshmallow\Ecommerce\Cart\Models\ShippingMethod;

it('books one discount line per vat rate, pro rata to what each rate is worth', function (): void {
    // 80.00 at 21% and 20.00 at 9%: a 10.00 discount splits 8.00 / 2.00.
    $cart = cartOf([[8000, 1, 21.0], [2000, 1, 9.0]]);

    $cart->applyDiscount(Discount::factory()->create(['fixed_amount' => 1000]));

    $lines = $cart->fresh()->discountItems()->sortBy('vat_percentage')->values();
    expect($lines)->toHaveCount(2)
        ->and((float) $lines[0]->vat_percentage)->toBe(9.0)
        ->and($lines[0]->price_including_vat)->toBe(-200)
        ->and((float) $lines[1]->vat_percentage)->toBe(21.0)
        ->and($lines[1]->price_including_vat)->toBe(-800)
        ->and($cart->fresh()->getDiscountAmount())->toBe(-1000)
        // -800 @21% carries -139 vat, -200 @9% carries -17 vat.
        ->and($cart->fresh()->getDiscountVatAmount())->toBe(-139 + -17);
});

it('reconciles the vat on the discount with the vat on what it discounts', function (): void {
    $cart = cartOf([[12100, 1, 21.0], [10900, 1, 9.0]]);

    $cart->applyDiscount(Discount::factory()->percentage(50)->create());

    $cart = $cart->fresh();
    // Half of each line: -6050 @21% (net -5000) and -5450 @9% (net -5000).
    expect($cart->getDiscountAmount())->toBe(-11500)
        ->and($cart->getDiscountAmountWithoutVat())->toBe(-10000)
        ->and($cart->getDiscountVatAmount())->toBe(-1500)
        ->and($cart->getTotalVatAmount())->toBe((2100 + 900) - 1500);
});

it('never loses or invents a cent when the split does not divide evenly', function (): void {
    // 3 lines at 3 rates, all worth 1.00: 1.00 off is 33/33/34.
    $cart = cartOf([[100, 1, 21.0], [100, 1, 9.0], [100, 1, 0.0]]);

    $cart->applyDiscount(Discount::factory()->create(['fixed_amount' => 100]));

    $parts = $cart->fresh()->discountItems()->pluck('price_including_vat')->sort()->values()->all();
    expect($parts)->toBe([-34, -33, -33])
        ->and($cart->fresh()->getDiscountAmount())->toBe(-100);
});

it('books a free shipping discount at the shipping line vat rate', function (): void {
    ShippingMethod::factory()->create(['price_including_vat' => 695, 'vat_percentage' => 21]);
    $cart = cartOf([[10900, 1, 9.0]]);

    $cart->applyDiscount(Discount::factory()->freeShipping()->create());

    $line = $cart->fresh()->discountItems()->sole();
    expect($line->price_including_vat)->toBe(-695)
        ->and((float) $line->vat_percentage)->toBe(21.0)
        ->and($line->price_excluding_vat)->toBe(-574)
        ->and($cart->fresh()->getShippingVatAmount() + $cart->fresh()->getDiscountVatAmount())->toBe(0);
});

it('removes every line of a code at once and keeps other codes', function (): void {
    $cart = cartOf([[8000, 1, 21.0], [2000, 1, 9.0]]);
    $cart->applyDiscount(Discount::factory()->create(['discount_code' => 'SPLIT', 'fixed_amount' => 1000, 'is_combinable' => true]));
    $cart->fresh()->applyDiscount(Discount::factory()->create(['discount_code' => 'OTHER', 'fixed_amount' => 500, 'is_combinable' => true]));
    expect($cart->fresh()->discountItems())->toHaveCount(4);

    $cart->fresh()->removeDiscount('SPLIT');

    expect($cart->fresh()->discountItems()->pluck('description')->unique()->all())->toBe(['OTHER'])
        ->and($cart->fresh()->getDiscountAmount())->toBe(-500);
});

it('re-applies a split code as one code when the cart changes', function (): void {
    $cart = cartOf([[8000, 1, 21.0], [2000, 1, 9.0]]);
    $cart->applyDiscount(Discount::factory()->percentage(10)->create(['discount_code' => 'TIEN']));

    $cart->fresh()->add(productPriced(1000, 9.0), 1);

    $cart = $cart->fresh();
    expect($cart->discounts()->pluck('discount_code')->all())->toBe(['TIEN'])
        ->and($cart->discountItems()->where('description', 'TIEN'))->toHaveCount(2)
        ->and($cart->getDiscountAmount())->toBe(-1100);
});

it('announces the total and the per-rate lines', function (): void {
    Event::fake([DiscountApplied::class]);
    $cart = cartOf([[8000, 1, 21.0], [2000, 1, 9.0]]);

    $cart->applyDiscount(Discount::factory()->create(['fixed_amount' => 1000]));

    Event::assertDispatched(DiscountApplied::class, fn (DiscountApplied $e): bool => $e->amount === -1000
        && $e->prices->count() === 2
        && $e->prices->sum(fn ($p) => $p->amountIncludingVat) === -1000);
});

it('snapshots a split code as one entry with its total', function (): void {
    $cart = cartOf([[8000, 1, 21.0], [2000, 1, 9.0]]);
    $cart->applyDiscount(Discount::factory()->create(['discount_code' => 'SPLIT', 'fixed_amount' => 1000]));

    expect($cart->fresh()->getPayableSnapshot()['discounts'])->toBe([['code' => 'SPLIT', 'amount' => -1000]]);
});

it('keeps a single-rate cart on a single line', function (): void {
    $cart = cartOf([[1000, 2, 21.0], [500, 1, 21.0]]);

    $cart->applyDiscount(Discount::factory()->percentage(10)->create());

    expect($cart->fresh()->discountItems())->toHaveCount(1)
        ->and($cart->fresh()->getDiscountAmount())->toBe(-250);
});
