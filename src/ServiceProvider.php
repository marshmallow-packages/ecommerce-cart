<?php

namespace Marshmallow\Ecommerce\Cart;

use Marshmallow\Ecommerce\Cart\EventServiceProvider;
use Illuminate\Support\ServiceProvider as BaseServiceProvider;

class ServiceProvider extends BaseServiceProvider
{
    /**
     * Register services.
     *
     * @return void
     */
    public function register()
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../config/cart.php',
            'cart'
        );
        $this->mergeConfigFrom(
            __DIR__ . '/../config/cart-discount.php',
            'cart-discount'
        );

        $this->app->register(EventServiceProvider::class);
    }

    /**
     * Bootstrap services.
     *
     * @return void
     */
    public function boot()
    {
        app('router')->aliasMiddleware('cart', config('cart.http.middleware.cart'));

        $this->loadResources();
        $this->registerPublishebles();
        $this->loadCommands();
    }

    protected function loadCommands()
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                config('cart.commands.clean_carts_command'),
            ]);
        }
    }

    protected function registerPublishebles()
    {
        $this->publishes([
            __DIR__ . '/../config/cart.php' => config_path('cart.php'),
        ], 'ecommerce-config');

        $this->publishes([
            __DIR__ . '/../config/cart-discount.php' => config_path('cart-discount.php'),
        ], 'ecommerce-discount-config');

        $this->publishes([
            __DIR__ . '/../resources/lang' => resource_path('lang/vendor/ecommerce/cart'),
        ], 'ecommerce-translations');
    }

    protected function loadResources()
    {
        $this->loadRoutesFrom(__DIR__ . '/../routes.php');
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
        $this->loadTranslationsFrom(__DIR__ . '/../resources/lang', 'ecommerce');
    }
}
