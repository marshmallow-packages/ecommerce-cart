<?php

declare(strict_types=1);

use Marshmallow\Ecommerce\Cart\Models\ShoppingCart;
use Marshmallow\Ecommerce\Cart\Support\Price;
use Workbench\App\Models\Product;

it('exposes value-object totals on an order item', function (): void {
    $cart = ShoppingCart::completelyNew();
    $cart->prospect->update(['email' => 'buyer@example.com']);
    $cart->add(Product::factory()->create(['price_cents' => 12100, 'vat_percentage' => 21]), 2);

    $order = $cart->fresh()->convertToOrder();
    $item = $order->items->first();

    expect($item->price())->toBeInstanceOf(Price::class)
        ->and($item->price()->amountIncludingVat)->toBe(12100)
        ->and($item->total()->amountIncludingVat)->toBe(24200)
        ->and($item->getUnitAmountWithoutVat())->toBe(10000)
        ->and($item->getTotalVatAmount())->toBe(4200);
});
