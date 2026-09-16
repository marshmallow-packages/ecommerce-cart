<?php

declare(strict_types=1);

namespace Marshmallow\Ecommerce\Cart;

use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\ServiceProvider;
use Marshmallow\Ecommerce\Cart\Console\Commands\CleanCartsCommand;

class CartServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/cart.php', 'cart');

        $this->app->singleton(Cart::class, fn (): Cart => new Cart);
    }

    public function boot(): void
    {
        $this->registerMiddleware();
        $this->registerAuthListeners();
        $this->loadJsonTranslationsFrom(__DIR__.'/../resources/lang');

        if ($this->app->runningInConsole()) {
            $this->bootConsole();
        }
    }

    protected function registerMiddleware(): void
    {
        $router = $this->app['router'];
        $alias = (string) config('cart.middleware.alias', 'cart');
        $router->aliasMiddleware($alias, config('cart.middleware.class'));
    }

    protected function registerAuthListeners(): void
    {
        /** @var Dispatcher $events */
        $events = $this->app['events'];

        foreach ((array) config('cart.listeners.login', []) as $listener) {
            $events->listen(Login::class, $listener);
        }

        foreach ((array) config('cart.listeners.logout', []) as $listener) {
            $events->listen(Logout::class, $listener);
        }
    }

    protected function bootConsole(): void
    {
        $this->commands([
            config('cart.commands.clean_carts', CleanCartsCommand::class),
        ]);

        $this->publishes([
            __DIR__.'/../config/cart.php' => config_path('cart.php'),
        ], 'cart-config');

        $this->publishes([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'cart-migrations');

        $this->publishes([
            __DIR__.'/../database/migrations-upgrade' => database_path('migrations'),
        ], 'cart-upgrade-migrations');

        $this->publishes([
            __DIR__.'/../resources/lang' => $this->app->langPath('vendor/cart'),
        ], 'cart-translations');
    }
}
