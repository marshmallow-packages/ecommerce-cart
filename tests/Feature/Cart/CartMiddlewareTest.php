<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Marshmallow\Ecommerce\Cart\Facades\Cart;
use Marshmallow\Ecommerce\Cart\Http\Middleware\CartMiddleware;
use Marshmallow\Ecommerce\Cart\Models\ShoppingCart;
use Workbench\App\Models\Product;

function runMiddleware(Request $request): Request
{
    $captured = $request;
    (new CartMiddleware)->handle($request, function (Request $passed) use (&$captured) {
        $captured = $passed;

        return response('ok');
    });

    return $captured;
}

function storefrontRequest(string $uri = '/shop'): Request
{
    $request = Request::create($uri);
    $request->setLaravelSession(app('session.store'));

    return $request;
}

it('attaches a cart to the storefront request', function (): void {
    $passed = runMiddleware(storefrontRequest());

    expect($passed->attributes->get('cart'))->toBeInstanceOf(ShoppingCart::class);
});

it('does not persist a cart for a visitor who has none yet', function (): void {
    $passed = runMiddleware(storefrontRequest());

    expect($passed->attributes->get('cart')->exists)->toBeFalse()
        ->and(ShoppingCart::count())->toBe(0);
});

it('attaches the cart the session already holds', function (): void {
    $cart = ShoppingCart::completelyNew();

    $passed = runMiddleware(storefrontRequest());

    expect($passed->attributes->get('cart')->is($cart))->toBeTrue()
        ->and(ShoppingCart::count())->toBe(1);
});

it('exposes the attached cart through the facade', function (): void {
    $cart = ShoppingCart::completelyNew();
    $request = storefrontRequest();

    runMiddleware($request);
    app()->instance('request', $request);

    expect(Cart::getFromRequest()->is($cart))->toBeTrue();
});

it('replaces a confirmed cart with a fresh one sharing the prospect', function (): void {
    $cart = ShoppingCart::completelyNew();
    $cart->forceFill(['confirmed_at' => now()])->save();

    $passed = runMiddleware(storefrontRequest());

    $attached = $passed->attributes->get('cart');
    expect($attached->is($cart))->toBeFalse()
        ->and($attached->prospect_id)->toBe($cart->prospect_id)
        ->and($attached->isOpen())->toBeTrue()
        ->and(session()->get(ShoppingCart::SESSION_KEY))->toBe($attached->id);
});

it('does nothing on an excluded path', function (): void {
    config()->set('cart.middleware.excluded_paths', ['admin/*']);

    $passed = runMiddleware(Request::create('/admin/orders'));

    expect($passed->attributes->get('cart'))->toBeNull();
});

it('matches any of several excluded patterns', function (string $uri, bool $excluded): void {
    config()->set('cart.middleware.excluded_paths', ['admin/*', 'nova-api/*', 'health']);

    $passed = runMiddleware(storefrontRequest($uri));

    expect($passed->attributes->has('cart'))->toBe(! $excluded);
})->with([
    'admin' => ['/admin/orders', true],
    'nova api' => ['/nova-api/orders', true],
    'exact health path' => ['/health', true],
    'storefront' => ['/shop/products', false],
    'admin-like storefront path' => ['/administration', false],
]);

it('treats an empty or missing exclusion list as excluding nothing', function (mixed $patterns): void {
    config()->set('cart.middleware.excluded_paths', $patterns);

    $passed = runMiddleware(storefrontRequest('/admin/orders'));

    expect($passed->attributes->has('cart'))->toBeTrue();
})->with([
    'empty' => [[]],
    'null' => [null],
]);

it('keeps the request cart valid once the visitor adds something', function (): void {
    $request = storefrontRequest();
    runMiddleware($request);
    app()->instance('request', $request);

    $cart = Cart::getFromRequest();
    $cart->add(Product::factory()->create(), 1);

    expect(Cart::getFromRequest()->exists)->toBeTrue()
        ->and(Cart::getFromRequest()->is(ShoppingCart::getBySession()))->toBeTrue()
        ->and(ShoppingCart::count())->toBe(1);
});

it('refuses to serve a storefront request with a product model that is not purchasable', function (): void {
    config()->set('cart.models.product', Workbench\App\Models\User::class);

    expect(fn () => runMiddleware(storefrontRequest()))->toThrow(InvalidArgumentException::class, 'must implement');
});

it('replaces a converted cart with a fresh one', function (): void {
    $cart = checkoutReadyCart();
    $cart->convertToOrder();

    $passed = runMiddleware(storefrontRequest());

    expect($passed->attributes->get('cart')->is($cart))->toBeFalse()
        ->and($passed->attributes->get('cart')->isOpen())->toBeTrue();
});
