<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Marshmallow\Ecommerce\Cart\Enums\CartItemType;
use Marshmallow\Ecommerce\Cart\Events\CartCreated;
use Marshmallow\Ecommerce\Cart\Events\ItemAdded;
use Marshmallow\Ecommerce\Cart\Events\ItemQuantityChanged;
use Marshmallow\Ecommerce\Cart\Exceptions\PurchasableUnavailableException;
use Marshmallow\Ecommerce\Cart\Models\ShoppingCart;
use Workbench\App\Models\Product;

it('creates a cart with a uuid, display id and guard token', function (): void {
    $cart = ShoppingCart::completelyNew();

    expect($cart->id)->toBeString()
        ->and($cart->display_id)->toBe(1)
        ->and(strlen($cart->guard_token))->toBe(64)
        ->and($cart->prospect)->not->toBeNull();
});

it('increments the display id per cart', function (): void {
    ShoppingCart::completelyNew();
    $second = ShoppingCart::completelyNew();

    expect($second->display_id)->toBe(2);
});

it('fires CartCreated when a cart is made', function (): void {
    Event::fake([CartCreated::class]);

    $cart = ShoppingCart::completelyNew();

    Event::assertDispatched(CartCreated::class, fn (CartCreated $e): bool => $e->cart->is($cart));
});

it('adds a product as a snapshotted line and fires ItemAdded', function (): void {
    Event::fake([ItemAdded::class]);
    $product = Product::factory()->create(['price_cents' => 12100, 'vat_percentage' => 21]);
    $cart = ShoppingCart::completelyNew();

    $item = $cart->add($product, 2);

    expect($item->type)->toBe(CartItemType::Product)
        ->and($item->quantity)->toBe(2)
        ->and($item->price_including_vat)->toBe(12100)
        ->and($item->price_excluding_vat)->toBe(10000)
        ->and((string) $item->purchasable_id)->toBe((string) $product->id);

    Event::assertDispatched(ItemAdded::class);
});

it('combines a repeat addition of the same product into one line', function (): void {
    $product = Product::factory()->create();
    $cart = ShoppingCart::completelyNew();

    $cart->add($product, 1);
    $cart->add($product, 2);

    expect($cart->fresh()->productItems())->toHaveCount(1)
        ->and($cart->fresh()->productItems()->first()->quantity)->toBe(3);
});

it('keeps lines separate when their meta differs', function (): void {
    $product = Product::factory()->create();
    $cart = ShoppingCart::completelyNew();

    $cart->add($product, 1, ['size' => 'M']);
    $cart->add($product, 1, ['size' => 'L']);

    expect($cart->fresh()->productItems())->toHaveCount(2);
});

it('fires ItemQuantityChanged when a line quantity moves', function (): void {
    Event::fake([ItemQuantityChanged::class]);
    $product = Product::factory()->create();
    $cart = ShoppingCart::completelyNew();
    $item = $cart->add($product, 1);

    $item->setQuantity(5);

    expect($item->fresh()->quantity)->toBe(5);
    Event::assertDispatched(ItemQuantityChanged::class, fn (ItemQuantityChanged $e): bool => $e->from === 1 && $e->to === 5);
});

it('never lets a quantity fall below one', function (): void {
    $product = Product::factory()->create();
    $cart = ShoppingCart::completelyNew();
    $item = $cart->add($product, 1);

    $item->decreaseQuantity(5);

    expect($item->fresh()->quantity)->toBe(1);
});

it('increases a quantity', function (): void {
    $product = Product::factory()->create();
    $cart = ShoppingCart::completelyNew();
    $item = $cart->add($product, 1);

    $item->increaseQuantity(2);

    expect($item->fresh()->quantity)->toBe(3);
});

it('does not fire a quantity change when the value is unchanged', function (): void {
    $product = Product::factory()->create();
    $cart = ShoppingCart::completelyNew();
    $item = $cart->add($product, 2);
    Event::fake([ItemQuantityChanged::class]);

    $item->setQuantity(2);

    Event::assertNotDispatched(ItemQuantityChanged::class);
});

it('rejects adding a product that is out of stock', function (): void {
    $product = Product::factory()->outOfStock()->create();
    $cart = ShoppingCart::completelyNew();

    $cart->add($product, 1);
})->throws(PurchasableUnavailableException::class);

it('skips the stock check when disabled in config', function (): void {
    config()->set('cart.stock.check_on_add', false);
    $product = Product::factory()->outOfStock()->create();
    $cart = ShoppingCart::completelyNew();

    $item = $cart->add($product, 1);

    expect($item->quantity)->toBe(1);
});

it('starts a fresh cart from addCustom when called on an unsaved cart', function (): void {
    $cartModel = new ShoppingCart;

    $item = $cartModel->add(Product::factory()->create(), 1);

    expect($item->cart)->not->toBeNull()
        ->and($item->cart->exists)->toBeTrue();
});
