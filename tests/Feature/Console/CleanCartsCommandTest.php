<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Marshmallow\Ecommerce\Cart\Events\CartAbandoned;
use Marshmallow\Ecommerce\Cart\Models\ShoppingCart;

function quietCart(int $daysAgo, array $attributes = []): ShoppingCart
{
    $cart = ShoppingCart::completelyNew();
    $cart->forceFill(array_merge(['updated_at' => now()->subDays($daysAgo)], $attributes))->saveQuietly();

    return $cart;
}

it('flags carts that have gone quiet as abandoned', function (): void {
    Event::fake([CartAbandoned::class]);
    $cart = quietCart(40);

    $this->artisan('ecommerce:clean-carts')->assertSuccessful();

    expect($cart->fresh()->abandoned_at)->not->toBeNull();
    Event::assertDispatched(CartAbandoned::class, fn (CartAbandoned $e): bool => $e->cart->is($cart));
});

it('reports what it did', function (): void {
    quietCart(40);
    quietCart(45);
    quietCart(120);

    $this->artisan('ecommerce:clean-carts')
        ->expectsOutputToContain('Flagged 3 abandoned cart(s) and pruned 1 expired cart(s).')
        ->assertSuccessful();
});

it('does not flag a cart twice', function (): void {
    $cart = quietCart(40, ['abandoned_at' => now()->subDay()]);

    Event::fake([CartAbandoned::class]);
    $this->artisan('ecommerce:clean-carts')->assertSuccessful();

    Event::assertNotDispatched(CartAbandoned::class);
});

it('skips abandonment events when disabled but still prunes', function (): void {
    config()->set('cart.abandoned.fire_events', false);
    Event::fake([CartAbandoned::class]);
    $quiet = quietCart(40);
    $expired = quietCart(120);

    $this->artisan('ecommerce:clean-carts')
        ->expectsOutputToContain('Flagged 0 abandoned cart(s) and pruned 1 expired cart(s).')
        ->assertSuccessful();

    Event::assertNotDispatched(CartAbandoned::class);
    expect($quiet->fresh()->abandoned_at)->toBeNull()
        ->and($expired->fresh()->trashed())->toBeTrue();
});

it('prunes carts older than the delete threshold', function (): void {
    $cart = quietCart(120);

    $this->artisan('ecommerce:clean-carts')->assertSuccessful();

    expect(ShoppingCart::withTrashed()->find($cart->id)->trashed())->toBeTrue();
});

it('leaves a recent cart untouched', function (): void {
    $cart = ShoppingCart::completelyNew();

    $this->artisan('ecommerce:clean-carts')->assertSuccessful();

    expect($cart->fresh()->abandoned_at)->toBeNull()
        ->and($cart->fresh()->trashed())->toBeFalse();
});

it('never touches a cart that is confirmed for payment', function (): void {
    Event::fake([CartAbandoned::class]);
    $cart = quietCart(400, ['confirmed_at' => now()->subDays(400)]);

    $this->artisan('ecommerce:clean-carts')->assertSuccessful();

    expect($cart->fresh())->not->toBeNull()
        ->and($cart->fresh()->abandoned_at)->toBeNull()
        ->and($cart->fresh()->trashed())->toBeFalse();
    Event::assertNotDispatched(CartAbandoned::class);
});

it('treats the thresholds as strictly older than', function (): void {
    $this->freezeTime();
    $onExpiry = quietCart(30);
    $pastExpiry = quietCart(31);
    $onDeletion = quietCart(90);
    $pastDeletion = quietCart(91);

    $this->artisan('ecommerce:clean-carts')->assertSuccessful();

    expect($onExpiry->fresh()->abandoned_at)->toBeNull()
        ->and($pastExpiry->fresh()->abandoned_at)->not->toBeNull()
        ->and($onDeletion->fresh()->trashed())->toBeFalse()
        ->and($pastDeletion->fresh()->trashed())->toBeTrue();
});

it('honours custom thresholds from config', function (): void {
    config()->set('cart.abandoned.expires_after_days', 7);
    config()->set('cart.abandoned.delete_after_days', 14);
    $week = quietCart(8);
    $fortnight = quietCart(15);

    $this->artisan('ecommerce:clean-carts')->assertSuccessful();

    expect($week->fresh()->abandoned_at)->not->toBeNull()
        ->and($week->fresh()->trashed())->toBeFalse()
        ->and($fortnight->fresh()->trashed())->toBeTrue();
});

it('does not count flagging as activity, so the cart is pruned later', function (): void {
    $cart = quietCart(40);
    $stamp = $cart->fresh()->updated_at;

    $this->artisan('ecommerce:clean-carts')->assertSuccessful();
    expect($cart->fresh()->updated_at->equalTo($stamp))->toBeTrue();

    $this->travel(60)->days();
    $this->artisan('ecommerce:clean-carts')->assertSuccessful();

    expect(ShoppingCart::withTrashed()->find($cart->id)->trashed())->toBeTrue();
});

it('leaves an already pruned cart alone', function (): void {
    $cart = quietCart(120);
    $cart->delete();

    $this->artisan('ecommerce:clean-carts')
        ->expectsOutputToContain('pruned 0 expired cart(s)')
        ->assertSuccessful();
});
