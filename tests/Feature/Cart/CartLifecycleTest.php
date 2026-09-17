<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Marshmallow\Ecommerce\Cart\Models\Customer;
use Marshmallow\Ecommerce\Cart\Models\Prospect;
use Marshmallow\Ecommerce\Cart\Models\ShoppingCart;
use Workbench\App\Models\User;

/*
|--------------------------------------------------------------------------
| Creation
|--------------------------------------------------------------------------
*/

it('links the signed-in user and their customer when a cart is minted', function (): void {
    $customer = Customer::factory()->create();
    $user = User::factory()->create(['customer_id' => $customer->id]);
    $this->actingAs($user);

    $cart = ShoppingCart::completelyNew();

    expect($cart->user_id)->toBe($user->id)
        ->and($cart->customer_id)->toBe($customer->id)
        ->and($cart->prospect_id)->not->toBeNull();
});

it('exposes the user it belongs to', function (): void {
    $user = User::factory()->create();
    $cart = ShoppingCart::completelyNew();
    $cart->connectUser($user);

    expect($cart->fresh()->user->is($user))->toBeTrue();
});

it('leaves the customer empty for a signed-in user without one', function (): void {
    $this->actingAs(User::factory()->create());

    expect(ShoppingCart::completelyNew()->customer_id)->toBeNull();
});

it('does not mint a prospect when one is handed in', function (): void {
    $prospect = Prospect::factory()->create();

    $cart = ShoppingCart::create(['prospect_id' => $prospect->id]);

    expect($cart->prospect_id)->toBe($prospect->id)
        ->and(Prospect::count())->toBe(1);
});

it('links the customer a fresh prospect already maps to by e-mail', function (): void {
    // A prospect minted for a cart has no e-mail yet, so no customer is found;
    // the link only appears once the prospect gains an identity.
    $cart = ShoppingCart::completelyNew();
    Customer::factory()->create(['email' => 'known@example.com']);

    expect($cart->customer_id)->toBeNull();

    $cart->prospect->update(['email' => 'known@example.com']);
    $cart->fresh()->addCustomerIfExists();

    expect($cart->fresh()->customer_id)->not->toBeNull();
});

it('keeps display ids unique across soft-deleted carts', function (): void {
    $first = ShoppingCart::completelyNew();
    $first->delete();

    $second = ShoppingCart::completelyNew();

    expect($second->display_id)->toBe($first->display_id + 1);
});

it('respects an explicit display id and key', function (): void {
    $cart = (new ShoppingCart)->forceFill(['id' => '11111111-1111-4111-8111-111111111111', 'display_id' => 500]);
    $cart->save();

    expect($cart->id)->toBe('11111111-1111-4111-8111-111111111111')
        ->and($cart->display_id)->toBe(500)
        ->and(ShoppingCart::completelyNew()->display_id)->toBe(501);
});

it('gives every cart its own guard token', function (): void {
    $a = ShoppingCart::completelyNew();
    $b = ShoppingCart::completelyNew();

    expect($a->guard_token)->not->toBe($b->guard_token)
        ->and($a->guard_token)->toMatch('/^[A-Za-z0-9]{64}$/');
});

it('stores the cart id and guard token in the session', function (): void {
    $cart = ShoppingCart::completelyNew();

    expect(session()->get(ShoppingCart::SESSION_KEY))->toBe($cart->id)
        ->and(session()->get(ShoppingCart::SESSION_TOKEN_KEY))->toBe($cart->guard_token);
});

/*
|--------------------------------------------------------------------------
| Session resolution
|--------------------------------------------------------------------------
*/

it('finds no cart when the session is empty', function (): void {
    expect(ShoppingCart::getBySession())->toBeNull();
});

it('finds no cart when the session points at a soft-deleted cart', function (): void {
    $cart = ShoppingCart::completelyNew();
    $cart->delete();

    expect(ShoppingCart::getBySession())->toBeNull();
});

it('finds no cart when the session points at an unknown id', function (): void {
    session()->put(ShoppingCart::SESSION_KEY, 'does-not-exist');

    expect(ShoppingCart::getBySession())->toBeNull();
});

it('refuses a tampered guard token', function (): void {
    $cart = ShoppingCart::completelyNew();

    session()->put(ShoppingCart::SESSION_TOKEN_KEY, str_repeat('x', 64));
    expect($cart->authorized())->toBeFalse();

    session()->put(ShoppingCart::SESSION_TOKEN_KEY, ['not', 'a', 'string']);
    expect($cart->authorized())->toBeFalse();
});

it('never authorises a cart without a guard token', function (): void {
    $legacy = new ShoppingCart;
    session()->put(ShoppingCart::SESSION_TOKEN_KEY, '');

    expect($legacy->authorized())->toBeFalse();

    $cart = ShoppingCart::completelyNew();
    $cart->forceFill(['guard_token' => ''])->saveQuietly();
    session()->put(ShoppingCart::SESSION_TOKEN_KEY, '');

    expect($cart->fresh()->authorized())->toBeFalse();
});

it('replaces a cart from before guard tokens existed with a fresh one', function (): void {
    // The upgrade migration leaves guard_token empty on carts it converts.
    $legacy = ShoppingCart::completelyNew();
    $legacy->forceFill(['guard_token' => ''])->saveQuietly();

    $resolved = ShoppingCart::getBySession();

    expect($resolved->is($legacy))->toBeFalse()
        ->and($resolved->guard_token)->toHaveLength(64)
        ->and(session()->get(ShoppingCart::SESSION_KEY))->toBe($resolved->id);
});

it('authorises a session that holds the token of an older cart', function (): void {
    $old = ShoppingCart::completelyNew();
    ShoppingCart::completelyNew();

    session()->put(ShoppingCart::SESSION_TOKEN_KEY, $old->guard_token);

    expect($old->authorized())->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Open carts per user
|--------------------------------------------------------------------------
*/

it('returns the latest open cart of a user, ignoring confirmed and foreign carts', function (): void {
    $user = User::factory()->create();
    $other = User::factory()->create();

    $this->travel(-3)->days();
    $older = ShoppingCart::completelyNew();
    $older->update(['user_id' => $user->id]);

    $this->travel(1)->days();
    $newer = ShoppingCart::completelyNew();
    $newer->update(['user_id' => $user->id]);

    $this->travel(1)->days();
    $confirmed = ShoppingCart::completelyNew();
    $confirmed->forceFill(['user_id' => $user->id, 'confirmed_at' => now()])->save();

    $foreign = ShoppingCart::completelyNew();
    $foreign->update(['user_id' => $other->id]);

    $this->travelBack();

    expect(ShoppingCart::latestOpenForUser($user)->is($newer))->toBeTrue()
        ->and(ShoppingCart::latestOpenForUser($other)->is($foreign))->toBeTrue();
});

it('returns null when a user has no open cart', function (): void {
    $user = User::factory()->create();
    $cart = ShoppingCart::completelyNew();
    $cart->update(['user_id' => $user->id]);
    $cart->delete();

    expect(ShoppingCart::latestOpenForUser($user))->toBeNull();
});

it('is open until confirmed for payment', function (): void {
    $cart = ShoppingCart::completelyNew();

    expect($cart->isOpen())->toBeTrue();

    $cart->add(productPriced(100), 1);
    $cart->fresh()->confirm();

    expect($cart->fresh()->isOpen())->toBeFalse()
        ->and($cart->fresh()->confirmed_at)->toBeInstanceOf(Carbon::class);
});

it('soft deletes a cart', function (): void {
    $cart = ShoppingCart::completelyNew();

    $cart->delete();

    expect(ShoppingCart::find($cart->id))->toBeNull()
        ->and(ShoppingCart::withTrashed()->find($cart->id))->not->toBeNull();
});
