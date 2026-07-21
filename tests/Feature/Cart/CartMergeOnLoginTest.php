<?php

declare(strict_types=1);

use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Support\Facades\Event;
use Marshmallow\Ecommerce\Cart\Events\CartMerged;
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
        ->and(session()->get(ShoppingCart::SESSION_KEY))->toBe($existing->id);

    Event::assertDispatched(CartMerged::class);
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

it('disconnects the cart from the user on logout', function (): void {
    $user = User::factory()->create();
    $cart = ShoppingCart::completelyNew();
    $cart->update(['user_id' => $user->id]);

    event(new Logout('web', $user));

    expect($cart->fresh()->user_id)->toBeNull();
});
