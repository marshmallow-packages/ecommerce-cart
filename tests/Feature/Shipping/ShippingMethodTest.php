<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Marshmallow\Ecommerce\Cart\Events\ShippingCalculated;
use Marshmallow\Ecommerce\Cart\Models\ShippingMethod;
use Marshmallow\Ecommerce\Cart\Models\ShoppingCart;

it('prefers the lowest sort value when several methods match', function (): void {
    ShippingMethod::factory()->create(['name' => 'Second', 'sort' => 20]);
    $first = ShippingMethod::factory()->create(['name' => 'First', 'sort' => 10]);
    ShippingMethod::factory()->create(['name' => 'Third', 'sort' => 30]);

    expect(ShippingMethod::calculateFromCart(cartOf([[1000, 1, 21.0]]))->is($first))->toBeTrue();
});

it('skips a method that is not yet valid and includes one inside its window', function (): void {
    ShippingMethod::factory()->create(['name' => 'Future', 'sort' => 1, 'valid_from' => now()->addDay()]);
    $current = ShippingMethod::factory()->create([
        'name' => 'Current',
        'sort' => 2,
        'valid_from' => now()->subDay(),
        'valid_till' => now()->addDay(),
    ]);

    expect(ShippingMethod::calculateFromCart(cartOf([[1000, 1, 21.0]]))->is($current))->toBeTrue()
        ->and(ShippingMethod::currentlyActive()->pluck('name')->all())->toBe(['Current']);
});

it('applies when any of its bands matches', function (): void {
    $method = ShippingMethod::factory()->create();
    $method->conditions()->create(['minimum_amount_including_vat' => 0, 'maximum_amount_including_vat' => 1000]);
    $method->conditions()->create(['minimum_amount_including_vat' => 5000, 'maximum_amount_including_vat' => 6000]);
    $method = $method->fresh()->load('conditions');

    expect($method->appliesToSubtotal(500))->toBeTrue()
        ->and($method->appliesToSubtotal(3000))->toBeFalse()
        ->and($method->appliesToSubtotal(5500))->toBeTrue()
        ->and($method->appliesToSubtotal(7000))->toBeFalse();
});

it('creates conditions through their factory', function (): void {
    $method = ShippingMethod::factory()->create();
    $condition = Marshmallow\Ecommerce\Cart\Models\ShippingMethodCondition::factory()->create([
        'shipping_method_id' => $method->id,
        'minimum_amount_including_vat' => 1000,
    ]);

    expect($condition->shippingMethod->is($method))->toBeTrue()
        ->and($method->fresh()->load('conditions')->appliesToSubtotal(999))->toBeFalse();
});

it('applies to every subtotal without conditions', function (): void {
    $method = ShippingMethod::factory()->create()->load('conditions');

    expect($method->appliesToSubtotal(0))->toBeTrue()
        ->and($method->appliesToSubtotal(PHP_INT_MAX))->toBeTrue();
});

it('is free exactly at its threshold and never free without one', function (): void {
    $threshold = ShippingMethod::factory()->create(['price_including_vat' => 495, 'free_from_amount' => 5000]);
    $always = ShippingMethod::factory()->create(['price_including_vat' => 495, 'free_from_amount' => null]);

    expect($threshold->priceForCart(cartOf([[4999, 1, 21.0]]))->amountIncludingVat)->toBe(495)
        ->and($threshold->priceForCart(cartOf([[5000, 1, 21.0]]))->amountIncludingVat)->toBe(0)
        ->and($threshold->priceForCart(cartOf([[5000, 1, 21.0]]))->vatPercentage)->toBe(21.0)
        ->and($always->priceForCart(cartOf([[1000000, 1, 21.0]]))->amountIncludingVat)->toBe(495);
});

it('normalises the currency and derives net and vat on save', function (): void {
    config()->set('cart.currency', 'EUR');
    $lower = ShippingMethod::factory()->create(['price_including_vat' => 1210, 'vat_percentage' => 21, 'currency' => 'usd']);
    $blank = ShippingMethod::factory()->create(['price_including_vat' => 1090, 'vat_percentage' => 9, 'currency' => '']);

    expect($lower->fresh()->currency)->toBe('USD')
        ->and($lower->fresh()->price_excluding_vat)->toBe(1000)
        ->and($lower->fresh()->vat_amount)->toBe(210)
        ->and($blank->fresh()->currency)->toBe('EUR')
        ->and($blank->fresh()->price_excluding_vat)->toBe(1000)
        ->and($blank->fresh()->vat_amount)->toBe(90)
        ->and($blank->fresh()->toPrice()->currency)->toBe('EUR');
});

it('re-derives net and vat when the gross price is edited', function (): void {
    $method = ShippingMethod::factory()->create(['price_including_vat' => 495, 'vat_percentage' => 21]);

    $method->update(['price_including_vat' => 695]);

    expect($method->fresh()->price_excluding_vat)->toBe(574)
        ->and($method->fresh()->vat_amount)->toBe(121);
});

it('casts its columns to the documented types', function (): void {
    $method = ShippingMethod::factory()->create(['free_from_amount' => '5000', 'valid_from' => '2026-01-01'])->fresh();

    expect($method->price_including_vat)->toBeInt()
        ->and($method->price_excluding_vat)->toBeInt()
        ->and($method->vat_amount)->toBeInt()
        ->and($method->vat_percentage)->toBeFloat()
        ->and($method->free_from_amount)->toBe(5000)
        ->and($method->valid_from)->toBeInstanceOf(Carbon::class)
        ->and($method->valid_till)->toBeNull();
});

it('picks the method for the subtotal band the cart lands in', function (int $subtotal, ?string $expected): void {
    $letter = ShippingMethod::factory()->create(['name' => 'Letter', 'sort' => 1]);
    $letter->conditions()->create(['minimum_amount_including_vat' => 0, 'maximum_amount_including_vat' => 999]);
    $parcel = ShippingMethod::factory()->create(['name' => 'Parcel', 'sort' => 2]);
    $parcel->conditions()->create(['minimum_amount_including_vat' => 1000, 'maximum_amount_including_vat' => 9999]);
    $pallet = ShippingMethod::factory()->create(['name' => 'Pallet', 'sort' => 3]);
    $pallet->conditions()->create(['minimum_amount_including_vat' => 100000]);

    $cart = cartOf([[$subtotal, 1, 21.0]]);

    expect(ShippingMethod::calculateFromCart($cart)?->name)->toBe($expected)
        ->and($cart->getShippingItem()?->description)->toBe($expected);
})->with([
    'letter band' => [500, 'Letter'],
    'top of the letter band' => [999, 'Letter'],
    'bottom of the parcel band' => [1000, 'Parcel'],
    'gap between bands' => [50000, null],
    'pallet band' => [100000, 'Pallet'],
]);

it('reports a zero cost with the method when shipping is free for the cart', function (): void {
    Event::fake([ShippingCalculated::class]);
    $method = ShippingMethod::factory()->create(['price_including_vat' => 495, 'free_from_amount' => 1000]);

    $cart = ShoppingCart::completelyNew();
    $cart->add(productPriced(5000), 1);

    expect($cart->fresh()->getShippingAmount())->toBe(0)
        ->and($cart->fresh()->shipping_method_id)->toBe($method->id)
        ->and($cart->fresh()->getShippingItem())->not->toBeNull();
    Event::assertDispatched(ShippingCalculated::class, fn (ShippingCalculated $e): bool => $e->method?->is($method) === true && $e->cost->isZero());
});

it('keeps the shipping line invisible in the cart summary', function (): void {
    ShippingMethod::factory()->create();
    $cart = cartOf([[1000, 1, 21.0]]);

    expect($cart->getShippingItem()->visible_in_cart)->toBeFalse()
        ->and($cart->visibleItems())->toHaveCount(1);
});
