<?php

declare(strict_types=1);

use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Marshmallow\Ecommerce\Cart\Events\CartMerged;
use Marshmallow\Ecommerce\Cart\Models\Customer;
use Marshmallow\Ecommerce\Cart\Models\ShoppingCart;
use Workbench\App\Models\Product;
use Workbench\App\Models\User;

it('adopts the guest cart when the user has no open cart', function (): void {
    $user = User::factory()->create();
    $guest = ShoppingCart::completelyNew();
    $guest->add(Product::factory()->create(), 1);

    event(new Login('web', $user, false));

    expect($guest->fresh()->user_id)->toBe($user->id);
});

it('merges the guest cart into the user existing open cart', function (): void {
    $user = User::factory()->create();
    $product = Product::factory()->create();

    $existing = ShoppingCart::completelyNew();
    $existing->update(['user_id' => $user->id]);
    $existing->add($product, 1);

    session()->forget(ShoppingCart::SESSION_KEY);
    $guest = ShoppingCart::completelyNew();
    $guest->add($product, 2);
    $guest->add(Product::factory()->create(), 1);

    Event::fake([CartMerged::class]);
    event(new Login('web', $user, false));

    $existing = $existing->fresh();
    expect($existing->productItems()->firstWhere('purchasable_id', (string) $product->id)->quantity)->toBe(3)
        ->and($existing->productItems())->toHaveCount(2)
        ->and(ShoppingCart::find($guest->id))->toBeNull()
        ->and($guest->fresh()->trashed())->toBeTrue()
        ->and(session()->get(ShoppingCart::SESSION_KEY))->toBe($existing->id)
        ->and(session()->get(ShoppingCart::SESSION_TOKEN_KEY))->toBe($existing->guard_token)
        ->and($existing->authorized())->toBeTrue();

    Event::assertDispatched(CartMerged::class, fn (CartMerged $e): bool => $e->target->is($existing) && $e->source->is($guest));
});

it('does not merge an out-of-stock line from the guest cart', function (): void {
    $user = User::factory()->create();
    $product = Product::factory()->create();

    $existing = ShoppingCart::completelyNew();
    $existing->update(['user_id' => $user->id]);
    $existing->add($product, 1);

    session()->forget(ShoppingCart::SESSION_KEY);
    $guest = ShoppingCart::completelyNew();
    config()->set('cart.stock.check_on_add', false);
    $guest->add(Product::factory()->outOfStock()->create(), 1);
    config()->set('cart.stock.check_on_add', true);

    event(new Login('web', $user, false));

    expect($existing->fresh()->productItems())->toHaveCount(1);
});

it('simply reconnects when the session cart already is the user open cart', function (): void {
    $user = User::factory()->create();
    $cart = ShoppingCart::completelyNew();
    $cart->update(['user_id' => $user->id]);
    $cart->add(Product::factory()->create(), 1);

    Event::fake([CartMerged::class]);
    event(new Login('web', $user, false));

    expect(ShoppingCart::count())->toBe(1)
        ->and($cart->fresh()->trashed())->toBeFalse()
        ->and($cart->fresh()->user_id)->toBe($user->id);
    Event::assertNotDispatched(CartMerged::class);
});

it('ignores a confirmed cart of the user and adopts the guest cart', function (): void {
    $user = User::factory()->create();
    $confirmed = ShoppingCart::completelyNew();
    $confirmed->forceFill(['user_id' => $user->id, 'confirmed_at' => now()])->save();

    session()->forget(ShoppingCart::SESSION_KEY);
    $guest = ShoppingCart::completelyNew();
    $guest->add(Product::factory()->create(), 1);

    event(new Login('web', $user, false));

    expect($guest->fresh()->user_id)->toBe($user->id)
        ->and($guest->fresh()->trashed())->toBeFalse()
        ->and($confirmed->fresh()->productItems())->toHaveCount(0)
        ->and(session()->get(ShoppingCart::SESSION_KEY))->toBe($guest->id);
});

it('switches the session to the user cart even when the guest cart is empty', function (): void {
    $user = User::factory()->create();
    $existing = ShoppingCart::completelyNew();
    $existing->update(['user_id' => $user->id]);
    $existing->add(Product::factory()->create(), 2);

    session()->forget(ShoppingCart::SESSION_KEY);
    $guest = ShoppingCart::completelyNew();

    event(new Login('web', $user, false));

    expect(session()->get(ShoppingCart::SESSION_KEY))->toBe($existing->id)
        ->and($existing->fresh()->productItems()->sole()->quantity)->toBe(2)
        ->and($guest->fresh()->trashed())->toBeTrue();
});

it('runs the whole flow through a real login and logout', function (): void {
    $customer = Customer::factory()->create();
    $user = User::factory()->create(['customer_id' => $customer->id]);
    $guest = ShoppingCart::completelyNew();
    $guest->add(Product::factory()->create(), 1);

    Auth::login($user);

    expect($guest->fresh()->user_id)->toBe($user->id)
        ->and($guest->fresh()->customer_id)->toBe($customer->id);

    Auth::logout();

    expect($guest->fresh()->user_id)->toBeNull()
        ->and($guest->fresh()->customer_id)->toBeNull()
        ->and($guest->fresh()->productItems())->toHaveCount(1);
});

it('disconnects the cart from the user on logout', function (): void {
    $user = User::factory()->create();
    $cart = ShoppingCart::completelyNew();
    $cart->update(['user_id' => $user->id]);

    event(new Logout('web', $user));

    expect($cart->fresh()->user_id)->toBeNull();
});

it('ignores logins and logouts on another guard', function (): void {
    $user = User::factory()->create();
    $customerCart = ShoppingCart::completelyNew();
    $customerCart->update(['user_id' => $user->id]);
    $customerCart->add(Product::factory()->create(), 1);

    session()->forget(ShoppingCart::SESSION_KEY);
    $adminSessionCart = ShoppingCart::completelyNew();
    $adminSessionCart->add(Product::factory()->create(), 1);

    // An admin with the same numeric id signs into a different guard.
    event(new Login('admin', $user, false));

    expect($customerCart->fresh()->productItems())->toHaveCount(1)
        ->and($adminSessionCart->fresh()->trashed())->toBeFalse()
        ->and($adminSessionCart->fresh()->user_id)->toBeNull()
        ->and(session()->get(ShoppingCart::SESSION_KEY))->toBe($adminSessionCart->id);

    event(new Logout('admin', $user));

    expect($customerCart->fresh()->user_id)->toBe($user->id);
});
