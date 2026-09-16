<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Marshmallow\Ecommerce\Cart\Enums\CartItemType;
use Marshmallow\Ecommerce\Cart\Events\ItemPriceChanged;
use Marshmallow\Ecommerce\Cart\Exceptions\CartLockedException;
use Marshmallow\Ecommerce\Cart\Exceptions\PaymentAmountMismatchException;
use Marshmallow\Ecommerce\Cart\Models\ShoppingCart;
use Marshmallow\Ecommerce\Cart\Support\Price;
use Workbench\App\Models\Product;

it('prices a line by its quantity tier', function (): void {
    $product = Product::factory()->tiered(['10' => 450])->create(['price_cents' => 475]);
    $cart = ShoppingCart::completelyNew();

    $below = $cart->add($product, 5);
    expect($below->price_including_vat)->toBe(475);

    $cart2 = ShoppingCart::completelyNew();
    $atTier = $cart2->add($product, 10);
    expect($atTier->price_including_vat)->toBe(450);
});

it('reprices a line when its quantity crosses a tier', function (): void {
    $product = Product::factory()->tiered(['10' => 450])->create(['price_cents' => 475]);
    $cart = ShoppingCart::completelyNew();
    $item = $cart->add($product, 5);

    $item->setQuantity(12);
    expect($item->refresh()->price_including_vat)->toBe(450);

    $item->setQuantity(3);
    expect($item->refresh()->price_including_vat)->toBe(475);
});

it('keeps a caller-chosen price out of tier repricing', function (): void {
    $product = Product::factory()->tiered(['10' => 450])->create(['price_cents' => 475]);
    $cart = ShoppingCart::completelyNew();
    $item = $cart->addCustom('Bundelprijs', Price::fromGross(400, 21), CartItemType::Product, purchasable: $product, quantity: 2);

    $item->setQuantity(15);

    expect($item->refresh()->price_including_vat)->toBe(400)
        ->and($cart->refresh()->refreshPrices())->toHaveCount(0)
        ->and($item->refresh()->price_including_vat)->toBe(400);
});

it('detects a purchasable whose price moved and reprices on demand', function (): void {
    Event::fake([ItemPriceChanged::class]);
    $product = Product::factory()->create(['price_cents' => 1000]);
    $cart = ShoppingCart::completelyNew();
    $item = $cart->add($product, 1);

    $product->update(['price_cents' => 1250]);

    $changed = $cart->refresh()->refreshPrices();

    expect($changed)->toHaveCount(1)
        ->and($item->refresh()->price_including_vat)->toBe(1250);
    Event::assertDispatched(ItemPriceChanged::class, fn (ItemPriceChanged $e): bool => $e->from->amountIncludingVat === 1000 && $e->to->amountIncludingVat === 1250);
});

it('leaves untouched prices alone when refreshing', function (): void {
    Event::fake([ItemPriceChanged::class]);
    $cart = ShoppingCart::completelyNew();
    $cart->add(Product::factory()->create(['price_cents' => 1000]), 1);

    expect($cart->refresh()->refreshPrices())->toHaveCount(0);
    Event::assertNotDispatched(ItemPriceChanged::class);
});

it('leaves lines whose product vanished untouched everywhere', function (): void {
    $product = Product::factory()->create(['price_cents' => 1000]);
    $cart = ShoppingCart::completelyNew();
    $cart->prospect?->update(['email' => 'sam@example.com']);
    $item = $cart->add($product, 1);
    $product->delete();

    // Quantity change cannot re-ask a vanished purchasable for a tier price.
    $item->refresh()->setQuantity(3);
    expect($item->refresh()->price_including_vat)->toBe(1000);

    // A price refresh silently skips the line.
    expect($cart->refresh()->refreshPrices())->toHaveCount(0);

    // And a reorder of the resulting order leaves the line off.
    $order = $cart->refresh()->convertToOrder();
    session()->forget(ShoppingCart::SESSION_KEY);
    expect($order->toNewCart()->productItems())->toHaveCount(0);
});

it('locks a confirmed cart against every mutation', function (): void {
    $product = Product::factory()->create(['price_cents' => 1000]);
    $cart = ShoppingCart::completelyNew();
    $item = $cart->add($product, 1);

    $cart->forceFill(['confirmed_at' => now()])->save();
    $cart = $cart->refresh();

    expect(fn () => $cart->add($product, 1))->toThrow(CartLockedException::class)
        ->and(fn () => $item->refresh()->setQuantity(5))->toThrow(CartLockedException::class)
        ->and(fn () => $item->refresh()->delete())->toThrow(CartLockedException::class)
        ->and(fn () => $cart->setFee('Toeslag', Price::fromGross(100, 21)))->toThrow(CartLockedException::class)
        ->and(fn () => $cart->selectShippingMethod(null))->toThrow(CartLockedException::class)
        ->and(fn () => $cart->refreshPrices())->toThrow(CartLockedException::class);
});

it('reopens when confirmed_at is cleared', function (): void {
    $product = Product::factory()->create(['price_cents' => 1000]);
    $cart = ShoppingCart::completelyNew();
    $cart->add($product, 1);
    $cart->forceFill(['confirmed_at' => now()])->save();

    $cart->refresh()->forceFill(['confirmed_at' => null])->save();

    expect($cart->refresh()->add($product, 1)->quantity)->toBe(2);
});

it('refuses to convert when the paid amount does not match', function (): void {
    $cart = ShoppingCart::completelyNew();
    $cart->prospect?->update(['email' => 'sam@example.com']);
    $cart->add(Product::factory()->create(['price_cents' => 1000]), 1);

    expect(fn () => $cart->refresh()->convertToOrder(expectedTotalAmount: 999))
        ->toThrow(PaymentAmountMismatchException::class);
});

it('converts when the paid amount matches to the cent', function (): void {
    $cart = ShoppingCart::completelyNew();
    $cart->prospect?->update(['email' => 'sam@example.com']);
    $cart->add(Product::factory()->create(['price_cents' => 1000]), 1);

    $order = $cart->refresh()->convertToOrder(expectedTotalAmount: 1000);

    expect($order->total_including_vat)->toBe(1000);
});

it('describes itself for a payment snapshot', function (): void {
    $cart = ShoppingCart::completelyNew();
    $cart->add(Product::factory()->create(['price_cents' => 1000, 'vat_percentage' => 21]), 2);
    $cart->refresh()->setFee('Toeslag', Price::fromGross(150, 21));

    $snapshot = $cart->refresh()->getPayableSnapshot();

    expect($snapshot['cart_id'])->toBe($cart->id)
        ->and($snapshot['display_id'])->toBe($cart->display_id)
        ->and($snapshot['total_amount'])->toBe(2150)
        ->and($snapshot['lines'])->toHaveCount(2)
        ->and($snapshot['lines'][0])->toMatchArray([
            'type' => 'PRODUCT',
            'quantity' => 2,
            'unit_amount' => 1000,
            'total_amount' => 2000,
        ]);
});
