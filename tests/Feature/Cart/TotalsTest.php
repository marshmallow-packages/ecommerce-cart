<?php

declare(strict_types=1);

use Marshmallow\Ecommerce\Cart\Models\ShippingMethod;
use Marshmallow\Ecommerce\Cart\Models\ShoppingCart;
use Workbench\App\Models\Product;

it('sums product lines into a subtotal', function (): void {
    $cart = ShoppingCart::completelyNew();
    $cart->add(Product::factory()->create(['price_cents' => 12100, 'vat_percentage' => 21]), 2);
    $cart->add(Product::factory()->create(['price_cents' => 1000, 'vat_percentage' => 21]), 1);

    $cart = $cart->fresh();

    expect($cart->getSubtotal())->toBe(25200)
        ->and($cart->getSubtotalWithoutVat())->toBe(20826)
        ->and($cart->productCount())->toBe(3);
});

it('adds shipping into the total when a method applies', function (): void {
    ShippingMethod::factory()->create(['price_including_vat' => 495, 'vat_percentage' => 21]);
    $cart = ShoppingCart::completelyNew();
    $cart->add(Product::factory()->create(['price_cents' => 10000, 'vat_percentage' => 21]), 1);

    $cart = $cart->fresh();

    expect($cart->getShippingAmount())->toBe(495)
        ->and($cart->getShippingAmountWithoutVat())->toBe(409)
        ->and($cart->getShippingVatAmount())->toBe(86)
        ->and($cart->getTotalAmount())->toBe(10495);
});

it('reports zero shipping when no method applies', function (): void {
    $cart = ShoppingCart::completelyNew();
    $cart->add(Product::factory()->create(['price_cents' => 10000, 'vat_percentage' => 21]), 1);

    $cart = $cart->fresh();

    expect($cart->getShippingAmount())->toBe(0)
        ->and($cart->getShippingItem())->toBeNull()
        ->and($cart->getTotalAmount())->toBe(10000);
});

it('breaks the grand total into net and vat', function (): void {
    $cart = ShoppingCart::completelyNew();
    $cart->add(Product::factory()->create(['price_cents' => 12100, 'vat_percentage' => 21]), 1);

    $cart = $cart->fresh();

    expect($cart->getTotalAmount())->toBe(12100)
        ->and($cart->getTotalAmountWithoutVat())->toBe(10000)
        ->and($cart->getTotalVatAmount())->toBe(2100);
});

it('only counts visible lines in the product count', function (): void {
    ShippingMethod::factory()->create(['price_including_vat' => 495]);
    $cart = ShoppingCart::completelyNew();
    $cart->add(Product::factory()->create(['price_cents' => 10000]), 2);

    $cart = $cart->fresh();

    // The shipping line is invisible, so it is not part of the product count.
    expect($cart->productCount())->toBe(2)
        ->and($cart->visibleItems())->toHaveCount(1);
});
