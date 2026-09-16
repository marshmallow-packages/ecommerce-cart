<?php

declare(strict_types=1);

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;
use Marshmallow\Ecommerce\Cart\Models\ShoppingCart;
use Ramsey\Uuid\Uuid;

it('finds the cart held in the session', function (): void {
    $cart = ShoppingCart::completelyNew();

    expect(ShoppingCart::getBySession()->is($cart))->toBeTrue();
});

it('returns a brand new cart when the session points at one without an owner', function (): void {
    $cart = ShoppingCart::completelyNew();
    $cart->forceFill(['prospect_id' => null, 'customer_id' => null, 'user_id' => null])->saveQuietly();

    $resolved = ShoppingCart::getBySession();

    expect($resolved->is($cart))->toBeFalse();
});

it('authorises only the session that created the cart', function (): void {
    $cart = ShoppingCart::completelyNew();

    expect($cart->authorized())->toBeTrue();

    session()->forget(ShoppingCart::SESSION_TOKEN_KEY);

    expect($cart->fresh()->authorized())->toBeFalse();
});

it('starts a new cart that keeps the same prospect', function (): void {
    $cart = ShoppingCart::completelyNew();

    $new = ShoppingCart::newWithSameProspect($cart);

    expect($new->is($cart))->toBeFalse()
        ->and($new->prospect_id)->toBe($cart->prospect_id);
});

it('retries when a fresh cart collides on its identifier', function (): void {
    $existing = ShoppingCart::completelyNew();
    $calls = 0;
    Str::createUuidsUsing(function () use ($existing, &$calls) {
        // Build the replacement UUID without going back through Str, which would
        // re-enter this very closure.
        return $calls++ === 0 ? $existing->id : Uuid::uuid4()->toString();
    });

    $new = ShoppingCart::completelyNew();

    Str::createUuidsNormally();

    expect($new->id)->not->toBe($existing->id);
});

it('gives up after repeated identifier collisions', function (): void {
    $existing = ShoppingCart::completelyNew();
    Str::createUuidsUsing(fn () => $existing->id);

    $call = fn () => ShoppingCart::completelyNew();

    try {
        expect($call)->toThrow(UniqueConstraintViolationException::class);
    } finally {
        Str::createUuidsNormally();
    }
});
