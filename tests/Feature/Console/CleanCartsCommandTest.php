<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Marshmallow\Ecommerce\Cart\Events\CartAbandoned;
use Marshmallow\Ecommerce\Cart\Models\Customer;
use Marshmallow\Ecommerce\Cart\Models\Prospect;
use Marshmallow\Ecommerce\Cart\Models\ShoppingCart;
use Marshmallow\Ecommerce\Cart\Models\ShoppingCartItem;

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

it('skips abandonment flagging when disabled but still prunes', function (string $key): void {
    config()->set("cart.abandoned.{$key}", false);
    Event::fake([CartAbandoned::class]);
    $quiet = quietCart(40);
    $expired = quietCart(120);

    $this->artisan('ecommerce:clean-carts')
        ->expectsOutputToContain('Flagged 0 abandoned cart(s) and pruned 1 expired cart(s).')
        ->assertSuccessful();

    Event::assertNotDispatched(CartAbandoned::class);
    expect($quiet->fresh()->abandoned_at)->toBeNull()
        ->and(ShoppingCart::withTrashed()->find($expired->id))->toBeNull();
})->with(['flag_abandoned', 'fire_events (pre-6.1 name)' => 'fire_events']);

it('prunes carts older than the delete threshold for good, lines included', function (): void {
    $cart = quietCart(120);
    $cart->add(productPriced(1000), 1);
    $cart->forceFill(['updated_at' => now()->subDays(120)])->saveQuietly();
    $cart->prospect->forceFill(['updated_at' => now()->subDays(120)])->saveQuietly();
    $prospectId = $cart->prospect_id;

    $this->artisan('ecommerce:clean-carts')->assertSuccessful();

    expect(ShoppingCart::withTrashed()->find($cart->id))->toBeNull()
        ->and(ShoppingCartItem::withTrashed()->where('shopping_cart_id', $cart->id)->count())->toBe(0)
        ->and(Prospect::withTrashed()->find($prospectId))->toBeNull();
});

it('prunes a cart that was soft-deleted earlier once it is old enough', function (): void {
    $merged = quietCart(120);
    $merged->delete();
    $merged->forceFill(['updated_at' => now()->subDays(120)])->saveQuietly();

    $this->artisan('ecommerce:clean-carts')
        ->expectsOutputToContain('pruned 1 expired cart(s)')
        ->assertSuccessful();

    expect(ShoppingCart::withTrashed()->find($merged->id))->toBeNull();
});

it('keeps a prospect that was converted or still has a cart or customer', function (): void {
    $converted = quietCart(120);
    $converted->prospect->forceFill(['converted_at' => now()->subDays(120), 'updated_at' => now()->subDays(120)])->saveQuietly();

    $shared = quietCart(120);
    $live = ShoppingCart::newWithSameProspect($shared);

    $withCustomer = quietCart(120);
    Customer::factory()->create(['prospect_id' => $withCustomer->prospect_id]);
    $withCustomer->prospect->forceFill(['updated_at' => now()->subDays(120)])->saveQuietly();

    $this->artisan('ecommerce:clean-carts')->assertSuccessful();

    expect(Prospect::find($converted->prospect_id))->not->toBeNull()
        ->and(Prospect::find($shared->prospect_id))->not->toBeNull()
        ->and($live->fresh())->not->toBeNull()
        ->and(Prospect::find($withCustomer->prospect_id))->not->toBeNull();
});

it('unflags an abandoned cart as soon as it sees activity', function (): void {
    $cart = quietCart(40);
    $this->artisan('ecommerce:clean-carts')->assertSuccessful();
    expect($cart->fresh()->abandoned_at)->not->toBeNull();

    $cart->fresh()->add(productPriced(1000), 1);

    expect($cart->fresh()->abandoned_at)->toBeNull();

    // Quiet again for long enough, it is flagged afresh.
    Event::fake([CartAbandoned::class]);
    $cart->fresh()->forceFill(['updated_at' => now()->subDays(40)])->saveQuietly();
    $this->artisan('ecommerce:clean-carts')->assertSuccessful();

    Event::assertDispatched(CartAbandoned::class);
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
        ->and($onDeletion->fresh())->not->toBeNull()
        ->and(ShoppingCart::withTrashed()->find($pastDeletion->id))->toBeNull();
});

it('honours custom thresholds from config', function (): void {
    config()->set('cart.abandoned.expires_after_days', 7);
    config()->set('cart.abandoned.delete_after_days', 14);
    $week = quietCart(8);
    $fortnight = quietCart(15);

    $this->artisan('ecommerce:clean-carts')->assertSuccessful();

    expect($week->fresh()->abandoned_at)->not->toBeNull()
        ->and(ShoppingCart::withTrashed()->find($fortnight->id))->toBeNull();
});

it('does not count flagging as activity, so the cart is pruned later', function (): void {
    $cart = quietCart(40);
    $stamp = $cart->fresh()->updated_at;

    $this->artisan('ecommerce:clean-carts')->assertSuccessful();
    expect($cart->fresh()->updated_at->equalTo($stamp))->toBeTrue();

    $this->travel(60)->days();
    $this->artisan('ecommerce:clean-carts')->assertSuccessful();

    expect(ShoppingCart::withTrashed()->find($cart->id))->toBeNull();
});

it('leaves a recently soft-deleted cart alone', function (): void {
    $cart = ShoppingCart::completelyNew();
    $cart->delete();

    $this->artisan('ecommerce:clean-carts')
        ->expectsOutputToContain('pruned 0 expired cart(s)')
        ->assertSuccessful();

    expect(ShoppingCart::withTrashed()->find($cart->id))->not->toBeNull();
});
