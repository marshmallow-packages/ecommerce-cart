<?php

declare(strict_types=1);

use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Marshmallow\Ecommerce\Cart\Cart;

it('binds the cart manager as a singleton', function (): void {
    expect(app(Cart::class))->toBeInstanceOf(Cart::class)
        ->and(app(Cart::class))->toBe(app(Cart::class));
});

it('registers the cart middleware alias', function (): void {
    expect(app('router')->getMiddleware())->toHaveKey('cart');
});

it('registers the console command', function (): void {
    expect(array_keys(app(Kernel::class)->all()))->toContain('ecommerce:clean-carts');
});

it('wires the login and logout listeners', function (): void {
    expect(Event::hasListeners(Login::class))->toBeTrue()
        ->and(Event::hasListeners(Logout::class))->toBeTrue();
});

it('publishes the config, migrations and translations', function (): void {
    expect(ServiceProvider::$publishGroups)
        ->toHaveKeys(['cart-config', 'cart-migrations', 'cart-upgrade-migrations', 'cart-translations']);
});
