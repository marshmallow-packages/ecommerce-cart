<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Marshmallow\Ecommerce\Cart\Events\CartAbandoned;
use Marshmallow\Ecommerce\Cart\Models\ShoppingCart;

it('flags carts that have gone quiet as abandoned', function (): void {
    Event::fake([CartAbandoned::class]);
    $cart = ShoppingCart::completelyNew();
    $cart->forceFill(['updated_at' => now()->subDays(40)])->saveQuietly();

    $this->artisan('ecommerce:clean-carts')->assertSuccessful();

    expect($cart->fresh()->abandoned_at)->not->toBeNull();
    Event::assertDispatched(CartAbandoned::class);
});

it('does not flag a cart twice', function (): void {
    $cart = ShoppingCart::completelyNew();
    $cart->forceFill(['updated_at' => now()->subDays(40), 'abandoned_at' => now()->subDay()])->saveQuietly();

    Event::fake([CartAbandoned::class]);
    $this->artisan('ecommerce:clean-carts')->assertSuccessful();

    Event::assertNotDispatched(CartAbandoned::class);
});

it('skips abandonment events when disabled', function (): void {
    config()->set('cart.abandoned.fire_events', false);
    Event::fake([CartAbandoned::class]);
    $cart = ShoppingCart::completelyNew();
    $cart->forceFill(['updated_at' => now()->subDays(40)])->saveQuietly();

    $this->artisan('ecommerce:clean-carts')->assertSuccessful();

    Event::assertNotDispatched(CartAbandoned::class);
    expect($cart->fresh()->abandoned_at)->toBeNull();
});

it('prunes carts older than the delete threshold', function (): void {
    $cart = ShoppingCart::completelyNew();
    $cart->forceFill(['updated_at' => now()->subDays(120)])->saveQuietly();

    $this->artisan('ecommerce:clean-carts')->assertSuccessful();

    expect(ShoppingCart::withTrashed()->find($cart->id)->trashed())->toBeTrue();
});

it('leaves a recent cart untouched', function (): void {
    $cart = ShoppingCart::completelyNew();

    $this->artisan('ecommerce:clean-carts')->assertSuccessful();

    expect($cart->fresh()->abandoned_at)->toBeNull()
        ->and($cart->fresh()->trashed())->toBeFalse();
});
