<?php

declare(strict_types=1);

use Marshmallow\Ecommerce\Cart\Models\ShoppingCart;
use Marshmallow\Ecommerce\Cart\Support\Price;
use Workbench\App\Models\Product;

beforeEach(function (): void {
    $this->cart = ShoppingCart::completelyNew();
});

it('exposes unit amounts from the snapshot columns', function (): void {
    $item = $this->cart->add(Product::factory()->create(['price_cents' => 12100, 'vat_percentage' => 21]), 3);

    expect($item->getUnitAmount())->toBe(12100)
        ->and($item->getUnitAmountWithoutVat())->toBe(10000)
        ->and($item->getUnitVatAmount())->toBe(2100);
});

it('multiplies the unit amounts by the quantity for line totals', function (): void {
    $item = $this->cart->add(Product::factory()->create(['price_cents' => 12100, 'vat_percentage' => 21]), 3);

    expect($item->getTotalAmount())->toBe(36300)
        ->and($item->getTotalAmountWithoutVat())->toBe(30000)
        ->and($item->getTotalVatAmount())->toBe(6300);
});

it('returns the unit and line prices as value objects', function (): void {
    $item = $this->cart->add(Product::factory()->create(['price_cents' => 495, 'vat_percentage' => 21]), 2);

    expect($item->price())->toBeInstanceOf(Price::class)
        ->and($item->price()->amountIncludingVat)->toBe(495)
        ->and($item->total()->amountIncludingVat)->toBe(990);
});
