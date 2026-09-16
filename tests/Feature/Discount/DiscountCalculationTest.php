<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Marshmallow\Ecommerce\Cart\Enums\CartItemType;
use Marshmallow\Ecommerce\Cart\Enums\DiscountAppliesTo;
use Marshmallow\Ecommerce\Cart\Enums\DiscountEligibility;
use Marshmallow\Ecommerce\Cart\Enums\DiscountPrerequisite;
use Marshmallow\Ecommerce\Cart\Enums\DiscountType;
use Marshmallow\Ecommerce\Cart\Events\DiscountApplied;
use Marshmallow\Ecommerce\Cart\Events\DiscountRejected;
use Marshmallow\Ecommerce\Cart\Exceptions\DiscountException;
use Marshmallow\Ecommerce\Cart\Models\Discount;
use Marshmallow\Ecommerce\Cart\Models\ShoppingCart;
use Marshmallow\Ecommerce\Cart\Support\Price;

/*
|--------------------------------------------------------------------------
| Scope
|--------------------------------------------------------------------------
*/

it('takes a scoped percentage over the eligible lines only', function (): void {
    $eligible = productPriced(10000);
    $cart = ShoppingCart::completelyNew();
    $cart->add($eligible, 2);
    $cart->add(productPriced(50000), 1);

    $cart->fresh()->applyDiscount(Discount::factory()->percentage(10)->create([
        'applies_to' => DiscountAppliesTo::Products,
        'applies_to_products' => [$eligible->id],
    ]));

    expect($cart->fresh()->getDiscountAmount())->toBe(-2000)
        ->and($cart->fresh()->getTotalAmount())->toBe(68000);
});

it('matches integer product ids against the string purchasable column', function (): void {
    $product = productPriced(10000);
    $cart = cartOf([]);
    $cart->add($product, 1);
    $discount = Discount::factory()->create([
        'applies_to' => DiscountAppliesTo::Products,
        'applies_to_products' => [(int) $product->id],
        'fixed_amount' => 500,
    ]);

    expect($discount->eligibleItems($cart->fresh()))->toHaveCount(1)
        ->and($cart->fresh()->productItems()->sole()->getRawOriginal('purchasable_id'))->toBeString();
});

it('matches a category discount when any category overlaps', function (): void {
    $multi = productPriced(10000, 21.0, ['category_ids' => [1, 7, 9]]);
    $other = productPriced(10000, 21.0, ['category_ids' => [2]]);
    $none = productPriced(10000, 21.0, ['category_ids' => []]);
    $cart = ShoppingCart::completelyNew();
    $cart->add($multi, 1);
    $cart->add($other, 1);
    $cart->add($none, 1);

    $discount = Discount::factory()->percentage(50)->create([
        'applies_to' => DiscountAppliesTo::Categories,
        'applies_to_product_categories' => [7, 8],
    ]);

    expect($discount->eligibleItems($cart->fresh())->pluck('purchasable_id')->map(fn ($id) => (int) $id)->all())->toBe([$multi->id]);

    $cart->fresh()->applyDiscount($discount);

    expect($cart->fresh()->getDiscountAmount())->toBe(-5000);
});

it('rejects a product scope that names no products', function (): void {
    $cart = cartOf([[10000, 1, 21.0]]);

    expect(fn () => $cart->applyDiscount(Discount::factory()->create([
        'applies_to' => DiscountAppliesTo::Products,
        'applies_to_products' => null,
    ])))->toThrow(DiscountException::class);
});

it('treats every product line as eligible under the all scope', function (): void {
    $cart = cartOf([[1000, 1, 21.0], [2000, 2, 9.0]]);

    expect(Discount::factory()->create()->eligibleItems($cart))->toHaveCount(2);
});

/*
|--------------------------------------------------------------------------
| Prerequisites
|--------------------------------------------------------------------------
*/

it('enforces the minimum purchase amount inclusively', function (int $subtotal, bool $allowed): void {
    $cart = cartOf([[$subtotal, 1, 21.0]]);
    $discount = Discount::factory()->create([
        'prerequisite_type' => DiscountPrerequisite::PurchaseAmount,
        'prerequisite_purchase_amount' => 5000,
        'fixed_amount' => 100,
    ]);

    if ($allowed) {
        $cart->applyDiscount($discount);
        expect($cart->fresh()->getDiscountAmount())->toBe(-100);
    } else {
        expect(fn () => $cart->applyDiscount($discount))->toThrow(DiscountException::class);
    }
})->with([
    'one cent short' => [4999, false],
    'exactly the minimum' => [5000, true],
    'above the minimum' => [5001, true],
]);

it('enforces the minimum quantity inclusively', function (int $quantity, bool $allowed): void {
    $cart = cartOf([[1000, $quantity, 21.0]]);
    $discount = Discount::factory()->create([
        'prerequisite_type' => DiscountPrerequisite::Quantity,
        'prerequisite_quantity' => 3,
        'fixed_amount' => 100,
    ]);

    if ($allowed) {
        $cart->applyDiscount($discount);
        expect($cart->fresh()->getDiscountAmount())->toBe(-100);
    } else {
        expect(fn () => $cart->applyDiscount($discount))->toThrow(DiscountException::class);
    }
})->with([
    'one short' => [2, false],
    'exactly the minimum' => [3, true],
    'above the minimum' => [4, true],
]);

it('measures a prerequisite against the eligible lines, not the whole cart', function (): void {
    $eligible = productPriced(1000);
    $cart = ShoppingCart::completelyNew();
    $cart->add($eligible, 1);
    $cart->add(productPriced(100000), 1);

    $discount = Discount::factory()->create([
        'applies_to' => DiscountAppliesTo::Products,
        'applies_to_products' => [$eligible->id],
        'prerequisite_type' => DiscountPrerequisite::PurchaseAmount,
        'prerequisite_purchase_amount' => 5000,
    ]);

    expect(fn () => $cart->fresh()->applyDiscount($discount))
        ->toThrow(DiscountException::class, 'minimum order value');
});

it('ignores prerequisite amounts when no prerequisite type is set', function (): void {
    $cart = cartOf([[100, 1, 21.0]]);

    $cart->applyDiscount(Discount::factory()->create([
        'prerequisite_type' => DiscountPrerequisite::None,
        'prerequisite_purchase_amount' => 999999,
        'prerequisite_quantity' => 999,
        'fixed_amount' => 50,
    ]));

    expect($cart->fresh()->getDiscountAmount())->toBe(-50);
});

/*
|--------------------------------------------------------------------------
| Validity window & eligibility
|--------------------------------------------------------------------------
*/

it('accepts a discount inside its validity window', function (): void {
    $cart = cartOf([[10000, 1, 21.0]]);

    $cart->applyDiscount(Discount::factory()->create([
        'starts_at' => now()->subMinute(),
        'ends_at' => now()->addMinute(),
        'fixed_amount' => 100,
    ]));

    expect($cart->fresh()->getDiscountAmount())->toBe(-100);
});

it('rejects a customer-only discount when the cart has no customer or the list is empty', function (): void {
    $cart = cartOf([[10000, 1, 21.0]]);

    expect(fn () => $cart->applyDiscount(Discount::factory()->create([
        'eligible_for' => DiscountEligibility::Customers,
        'eligible_for_customers' => null,
    ])))->toThrow(DiscountException::class, 'log in');
});

it('rejects an e-mail discount when the cart has no e-mail at all', function (): void {
    $cart = cartOf([[10000, 1, 21.0]]);

    expect(fn () => $cart->applyDiscount(Discount::factory()->create([
        'eligible_for' => DiscountEligibility::Emails,
        'eligible_for_emails' => ['vip@example.com'],
    ])))->toThrow(DiscountException::class, 'not one of them');
});

it('matches eligible e-mails exactly', function (): void {
    $cart = cartOf([[10000, 1, 21.0]]);
    $cart->prospect->update(['email' => 'VIP@example.com']);

    expect(fn () => $cart->fresh()->applyDiscount(Discount::factory()->create([
        'eligible_for' => DiscountEligibility::Emails,
        'eligible_for_emails' => ['vip@example.com'],
    ])))->toThrow(DiscountException::class);
});

/*
|--------------------------------------------------------------------------
| Amount, rate & currency of the discount line
|--------------------------------------------------------------------------
*/

it('books the discount line at the single vat rate of the cart', function (): void {
    $cart = cartOf([[10900, 1, 9.0]]);

    $cart->applyDiscount(Discount::factory()->create(['fixed_amount' => 1090]));

    $line = $cart->fresh()->discountItems()->sole();
    expect((float) $line->vat_percentage)->toBe(9.0)
        ->and($line->price_including_vat)->toBe(-1090)
        ->and($line->price_excluding_vat)->toBe(-1000)
        ->and($line->vat_amount)->toBe(-90)
        ->and($line->price_excluding_vat + $line->vat_amount)->toBe($line->price_including_vat);
});

it('reconciles the discount totals on the cart', function (): void {
    $cart = cartOf([[10000, 1, 21.0]]);

    $cart->applyDiscount(Discount::factory()->create(['fixed_amount' => 1210]));
    $cart = $cart->fresh();

    expect($cart->getDiscountAmount())->toBe(-1210)
        ->and($cart->getDiscountAmountWithoutVat())->toBe(-1000)
        ->and($cart->getDiscountVatAmount())->toBe(-210)
        ->and($cart->getTotalVatAmount())->toBe($cart->getTotalAmount() - $cart->getTotalAmountWithoutVat());
});

it('books the discount line in the currency of the cart', function (): void {
    $cart = ShoppingCart::completelyNew();
    $cart->addCustom('Dollar item', Price::fromGross(10000, 21, 'USD'), CartItemType::Product);

    $cart->fresh()->applyDiscount(Discount::factory()->create(['fixed_amount' => 500]));

    expect($cart->fresh()->discountItems()->sole()->currency)->toBe('USD');
});

it('rounds a percentage discount half up at the cents level', function (): void {
    $cart = cartOf([[999, 1, 21.0]]);

    $cart->applyDiscount(Discount::factory()->percentage(10)->create());

    expect($cart->fresh()->getDiscountAmount())->toBe(-100);
});

it('books a free shipping discount of zero when there is nothing to ship', function (): void {
    $cart = cartOf([[1000, 1, 21.0]]);

    $cart->applyDiscount(Discount::factory()->freeShipping()->create());

    expect($cart->fresh()->discountItems()->sole()->price_including_vat)->toBe(0)
        ->and($cart->fresh()->getTotalAmount())->toBe(1000);
});

it('never lets a scoped percentage go negative after an earlier code', function (): void {
    $eligible = productPriced(1000);
    $cart = ShoppingCart::completelyNew();
    $cart->add($eligible, 1);
    $cart->add(productPriced(10000), 1);
    $cart->fresh()->applyDiscount(Discount::factory()->create(['discount_code' => 'BIG', 'fixed_amount' => 5000, 'is_combinable' => true]));

    $cart->fresh()->applyDiscount(Discount::factory()->percentage(50)->create([
        'discount_code' => 'HALF',
        'is_combinable' => true,
        'applies_to' => DiscountAppliesTo::Products,
        'applies_to_products' => [$eligible->id],
    ]));

    // 50% of max(0, 1000 - 5000) is nothing; the fixed code stays.
    expect($cart->fresh()->getDiscountAmount())->toBe(-5000)
        ->and($cart->fresh()->discountItems()->firstWhere('description', 'HALF')->price_including_vat)->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Events & messages
|--------------------------------------------------------------------------
*/

it('announces the applied discount with its negative amount', function (): void {
    Event::fake([DiscountApplied::class]);
    $cart = cartOf([[10000, 1, 21.0]]);
    $discount = Discount::factory()->create(['fixed_amount' => 2500]);

    $cart->applyDiscount($discount);

    Event::assertDispatched(DiscountApplied::class, fn (DiscountApplied $e): bool => $e->cart->is($cart)
        && $e->discount->is($discount)
        && $e->amount->amountIncludingVat === -2500
        && $e->amount->currency === 'EUR');
});

it('announces a rejected discount with the reason shown to the customer', function (): void {
    Event::fake([DiscountRejected::class]);
    $cart = cartOf([[10000, 1, 21.0]]);
    $discount = Discount::factory()->create(['is_active' => false]);

    try {
        $cart->applyDiscount($discount);
    } catch (DiscountException) {
    }

    Event::assertDispatched(DiscountRejected::class, fn (DiscountRejected $e): bool => $e->discount?->is($discount) === true
        && $e->reason === 'This voucher is not active yet. Please try again later.');
});

it('explains every rejection in customer-facing words', function (array $attributes, string $message): void {
    $cart = cartOf([[1000, 1, 21.0]]);
    $cart->prospect->update(['email' => 'someone@example.com']);

    expect(fn () => $cart->fresh()->applyDiscount(Discount::factory()->create($attributes)))
        ->toThrow(DiscountException::class, $message);
})->with([
    'inactive' => [['is_active' => false], 'This voucher is not active yet. Please try again later.'],
    'not started' => [['starts_at' => now()->addDay()], 'This voucher is not active yet. Please try again later.'],
    'ended' => [['ends_at' => now()->subDay()], 'This voucher is not active anymore. It looks like you are a little too late.'],
    'nothing eligible' => [['applies_to' => DiscountAppliesTo::Products, 'applies_to_products' => [0]], 'This voucher cannot be used with the items in your shopping cart.'],
    'minimum amount' => [['prerequisite_type' => DiscountPrerequisite::PurchaseAmount, 'prerequisite_purchase_amount' => 5000], 'This voucher can only be used with a minimum order value of'],
    'minimum quantity' => [['prerequisite_type' => DiscountPrerequisite::Quantity, 'prerequisite_quantity' => 3], 'This voucher can only be used if you order at least 3 of these products.'],
    'customers only' => [['eligible_for' => DiscountEligibility::Customers, 'eligible_for_customers' => [0]], 'This voucher can only be used by some customers. Please log in to your account and try again.'],
    'emails only' => [['eligible_for' => DiscountEligibility::Emails, 'eligible_for_emails' => ['other@example.com']], 'This voucher can only be used by some customers. Sadly, you are not one of them.'],
]);

it('formats the minimum order value as money in the message', function (): void {
    $cart = cartOf([[1000, 1, 21.0]]);

    expect(fn () => $cart->applyDiscount(Discount::factory()->create([
        'prerequisite_type' => DiscountPrerequisite::PurchaseAmount,
        'prerequisite_purchase_amount' => 123456,
    ])))->toThrow(DiscountException::class, '1.234,56');
});

it('speaks Dutch when the application does', function (): void {
    app()->setLocale('nl');
    $cart = cartOf([[1000, 1, 21.0]]);

    expect(fn () => $cart->applyDiscount(Discount::factory()->create(['is_active' => false])))
        ->toThrow(DiscountException::class, 'Deze kortingscode is nog niet actief. Probeer het later opnieuw.');
});

/*
|--------------------------------------------------------------------------
| Removal & combination edge cases
|--------------------------------------------------------------------------
*/

it('ignores a removal of a code that is not applied', function (): void {
    $cart = cartOf([[10000, 1, 21.0]]);
    $cart->applyDiscount(Discount::factory()->create(['discount_code' => 'KEEP', 'fixed_amount' => 100]));

    $cart->fresh()->removeDiscount('ONBEKEND');

    expect($cart->fresh()->discountItems()->sole()->description)->toBe('KEEP');
});

it('treats an applied code whose discount vanished as non-combinable', function (): void {
    $cart = cartOf([[10000, 1, 21.0]]);
    $gone = Discount::factory()->create(['discount_code' => 'WEG', 'fixed_amount' => 100, 'is_combinable' => true]);
    $cart->applyDiscount($gone);
    $gone->delete();

    $cart->fresh()->applyDiscount(Discount::factory()->create(['discount_code' => 'NIEUW', 'fixed_amount' => 200, 'is_combinable' => true]));

    expect($cart->fresh()->discountItems()->sole()->description)->toBe('NIEUW');
});

it('drops an applied code whose discount vanished on the next recalculation', function (): void {
    $cart = cartOf([[10000, 1, 21.0]]);
    $gone = Discount::factory()->create(['discount_code' => 'WEG', 'fixed_amount' => 100]);
    $cart->applyDiscount($gone);
    $gone->delete();

    $cart->fresh()->add(productPriced(1000), 1);

    expect($cart->fresh()->discountItems())->toHaveCount(0);
});

it('casts its columns to the documented types', function (): void {
    $discount = Discount::factory()->percentage(12.5)->create([
        'applies_to_products' => [1, 2],
        'eligible_for_emails' => ['a@example.com'],
        'is_combinable' => 1,
        'total_usage_limit' => '5',
        'starts_at' => '2026-01-01 00:00:00',
    ])->fresh();

    expect($discount->discount_type)->toBeInstanceOf(DiscountType::class)
        ->and($discount->applies_to)->toBe(DiscountAppliesTo::All)
        ->and($discount->prerequisite_type)->toBe(DiscountPrerequisite::None)
        ->and($discount->eligible_for)->toBe(DiscountEligibility::All)
        ->and($discount->applies_to_products)->toBe([1, 2])
        ->and($discount->eligible_for_emails)->toBe(['a@example.com'])
        ->and($discount->is_active)->toBeTrue()
        ->and($discount->is_combinable)->toBeTrue()
        ->and($discount->is_once_per_customer)->toBeFalse()
        ->and($discount->total_usage_limit)->toBe(5)
        ->and($discount->percentage_amount)->toBe(12.5)
        ->and($discount->fixed_amount)->toBeNull()
        ->and($discount->starts_at)->toBeInstanceOf(Carbon::class)
        ->and($discount->ends_at)->toBeNull();
});
