<?php

declare(strict_types=1);

use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Marshmallow\Ecommerce\Cart\Cart;
use Marshmallow\Ecommerce\Cart\CartServiceProvider;
use Marshmallow\Ecommerce\Cart\Facades\Cart as CartFacade;
use Marshmallow\Ecommerce\Cart\Http\Middleware\CartMiddleware;
use Marshmallow\Ecommerce\Cart\Listeners\DisconnectCartFromUser;
use Marshmallow\Ecommerce\Cart\Listeners\MergeCartOnLogin;
use Marshmallow\Ecommerce\Cart\Models\ShoppingCart;

it('binds the cart manager as a singleton', function (): void {
    expect(app(Cart::class))->toBeInstanceOf(Cart::class)
        ->and(app(Cart::class))->toBe(app(Cart::class));
});

it('resolves the facade to the singleton', function (): void {
    expect(CartFacade::getFacadeRoot())->toBe(app(Cart::class));
});

it('registers the cart middleware alias', function (): void {
    expect(app('router')->getMiddleware())->toHaveKey('cart')
        ->and(app('router')->getMiddleware()['cart'])->toBe(CartMiddleware::class);
});

it('honours a custom middleware alias', function (): void {
    config()->set('cart.middleware.alias', 'winkelwagen');

    (new CartServiceProvider(app()))->boot();

    expect(app('router')->getMiddleware())->toHaveKey('winkelwagen');
});

it('registers the console command', function (): void {
    expect(array_keys(app(Kernel::class)->all()))->toContain('ecommerce:clean-carts');
});

it('wires the login and logout listeners', function (): void {
    expect(Event::hasListeners(Login::class))->toBeTrue()
        ->and(Event::hasListeners(Logout::class))->toBeTrue()
        ->and(config('cart.listeners.login'))->toBe([MergeCartOnLogin::class])
        ->and(config('cart.listeners.logout'))->toBe([DisconnectCartFromUser::class]);
});

it('lets a host opt out of the auth listeners', function (): void {
    Event::forget(Login::class);
    Event::forget(Logout::class);
    config()->set('cart.listeners.login', []);
    config()->set('cart.listeners.logout', []);

    (new CartServiceProvider(app()))->boot();

    expect(Event::hasListeners(Login::class))->toBeFalse()
        ->and(Event::hasListeners(Logout::class))->toBeFalse();
});

it('publishes the config, migrations and translations', function (): void {
    expect(ServiceProvider::$publishGroups)
        ->toHaveKeys(['cart-config', 'cart-migrations', 'cart-upgrade-migrations', 'cart-translations']);
});

it('merges sensible defaults into the config', function (): void {
    expect(config('cart.currency'))->toBe('EUR')
        ->and(config('cart.locale'))->toBe('nl_NL')
        ->and(config('cart.prices_include_vat'))->toBeTrue()
        ->and(config('cart.default_vat_percentage'))->toBe(21.0)
        ->and(config('cart.customer_guard'))->toBe('web')
        ->and(config('cart.stock'))->toBe(['check_on_add' => true, 'check_on_checkout' => true])
        ->and(config('cart.abandoned'))->toBe(['expires_after_days' => 30, 'delete_after_days' => 90, 'fire_events' => true])
        ->and(config('cart.models'))->toHaveKeys([
            'user', 'product', 'prospect', 'customer', 'discount', 'shopping_cart', 'shopping_cart_item',
            'order', 'order_item', 'shipping_method', 'shipping_method_condition', 'address', 'country',
        ])
        ->and(config('cart.models.shopping_cart'))->toBe(ShoppingCart::class);
});

it('ships Dutch translations for the customer-facing strings', function (): void {
    app()->setLocale('nl');

    expect(__('Order'))->toBe('Bestelling')
        ->and(__('This voucher cannot be used with the items in your shopping cart.'))
        ->toBe('Deze kortingscode kan niet worden gebruikt met de artikelen in je winkelmand.');
});
