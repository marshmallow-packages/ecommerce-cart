<?php

declare(strict_types=1);

use Marshmallow\Ecommerce\Cart\Models\ShippingMethod;
use Marshmallow\Ecommerce\Cart\Models\ShoppingCart;
use Marshmallow\Ecommerce\Cart\Support\Price;
use Workbench\App\Models\Product;

it('derives net and vat when a shipping method is saved', function (): void {
    $method = ShippingMethod::factory()->create(['price_including_vat' => 495, 'vat_percentage' => 21]);

    expect($method->price_excluding_vat)->toBe(409)
        ->and($method->vat_amount)->toBe(86)
        ->and($method->toPrice())->toBeInstanceOf(Price::class);
});

it('applies a method without conditions to any cart', function (): void {
    ShippingMethod::factory()->create(['price_including_vat' => 495]);
    $cart = ShoppingCart::completelyNew();
    $cart->add(Product::factory()->create(['price_cents' => 5000]), 1);

    expect($cart->fresh()->getShippingItem())->not->toBeNull();
});

it('picks the method whose condition band matches the subtotal', function (): void {
    $cheap = ShippingMethod::factory()->create(['name' => 'Paid', 'price_including_vat' => 495, 'sort' => 1]);
    $cheap->conditions()->create(['minimum_amount_including_vat' => 0, 'maximum_amount_including_vat' => 9999]);

    $free = ShippingMethod::factory()->create(['name' => 'Free', 'price_including_vat' => 0, 'sort' => 2]);
    $free->conditions()->create(['minimum_amount_including_vat' => 10000, 'maximum_amount_including_vat' => null]);

    $cart = ShoppingCart::completelyNew();
    $cart->add(Product::factory()->create(['price_cents' => 15000]), 1);

    expect($cart->fresh()->getShippingItem()->description)->toBe('Free');
});

it('selects no method when the subtotal falls outside every band', function (): void {
    $method = ShippingMethod::factory()->create(['price_including_vat' => 495]);
    $method->conditions()->create(['minimum_amount_including_vat' => 100000, 'maximum_amount_including_vat' => null]);

    $cart = ShoppingCart::completelyNew();
    $cart->add(Product::factory()->create(['price_cents' => 5000]), 1);

    expect($cart->fresh()->getShippingItem())->toBeNull();
});

it('ignores an expired shipping method', function (): void {
    ShippingMethod::factory()->expired()->create(['price_including_vat' => 495]);
    $cart = ShoppingCart::completelyNew();
    $cart->add(Product::factory()->create(['price_cents' => 5000]), 1);

    expect($cart->fresh()->getShippingItem())->toBeNull();
});

it('returns no method when none are configured', function (): void {
    $cart = ShoppingCart::completelyNew();

    expect(ShippingMethod::calculateFromCart($cart))->toBeNull();
});

it('returns no method when the cart excludes shipping', function (): void {
    ShippingMethod::factory()->create(['price_including_vat' => 495]);
    $cart = Mockery::mock(ShoppingCart::class)->makePartial();
    $cart->shouldReceive('hasExcludedShipping')->andReturnTrue();

    expect(ShippingMethod::calculateFromCart($cart))->toBeNull();
});
