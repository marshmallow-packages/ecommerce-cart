<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Marshmallow\Ecommerce\Cart\Http\Middleware\CartMiddleware;
use Marshmallow\Ecommerce\Cart\Models\ShoppingCart;

function runMiddleware(Request $request): Request
{
    $captured = $request;
    (new CartMiddleware)->handle($request, function (Request $passed) use (&$captured) {
        $captured = $passed;

        return response('ok');
    });

    return $captured;
}

it('attaches a cart to the storefront request', function (): void {
    $request = Request::create('/shop');
    $request->setLaravelSession(app('session.store'));

    $passed = runMiddleware($request);

    expect($passed->attributes->get('cart'))->toBeInstanceOf(ShoppingCart::class);
});

it('replaces a confirmed cart with a fresh one sharing the prospect', function (): void {
    $cart = ShoppingCart::completelyNew();
    $cart->update(['confirmed_at' => now()]);

    $request = Request::create('/shop');
    $request->setLaravelSession(app('session.store'));

    $passed = runMiddleware($request);

    $attached = $passed->attributes->get('cart');
    expect($attached->is($cart))->toBeFalse()
        ->and($attached->prospect_id)->toBe($cart->prospect_id);
});

it('does nothing on an excluded path', function (): void {
    config()->set('cart.middleware.excluded_paths', ['admin/*']);
    $request = Request::create('/admin/orders');

    $passed = runMiddleware($request);

    expect($passed->attributes->get('cart'))->toBeNull();
});
