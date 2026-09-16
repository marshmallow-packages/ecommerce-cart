<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Marshmallow\Ecommerce\Cart\Facades\Cart;
use Marshmallow\Ecommerce\Cart\Models\ShoppingCart;

it('resolves the current cart, creating one when the session is empty', function (): void {
    $cart = Cart::get();

    expect($cart)->toBeInstanceOf(ShoppingCart::class)
        ->and($cart->exists)->toBeTrue();
});

it('returns the existing session cart on subsequent calls', function (): void {
    $first = Cart::get();
    $second = Cart::get();

    expect($first->is($second))->toBeTrue()
        ->and(ShoppingCart::count())->toBe(1);
});

it('starts over when the session cart was deleted', function (): void {
    $first = Cart::get();
    $first->delete();

    $second = Cart::get();

    expect($second->is($first))->toBeFalse()
        ->and($second->exists)->toBeTrue();
});

it('attaches a cart to the request and reads it back', function (): void {
    $cart = ShoppingCart::completelyNew();
    $request = Request::create('/');

    Cart::addToRequest($request, $cart);
    app()->instance('request', $request);

    expect(Cart::getFromRequest()->is($cart))->toBeTrue();
});

it('returns null when no cart is on the request', function (): void {
    app()->instance('request', Request::create('/'));

    expect(Cart::getFromRequest())->toBeNull();
});

it('returns null when the request attribute holds something else', function (): void {
    $request = Request::create('/');
    $request->attributes->set('cart', 'not-a-cart');
    app()->instance('request', $request);

    expect(Cart::getFromRequest())->toBeNull();
});

it('uses the configured customer guard', function (): void {
    config()->set('cart.customer_guard', 'api');

    expect(Cart::getUserGuard())->toBe('api');
});

it('falls back to the default guard when none is configured', function (): void {
    config()->set('cart.customer_guard', null);

    expect(Cart::getUserGuard())->toBe(config('auth.defaults.guard'));
});
