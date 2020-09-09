<?php

namespace Marshmallow\Ecommerce\Cart;

use Illuminate\Support\ServiceProvider as BaseServiceProvider;
use Marshmallow\Ecommerce\Cart\Http\Middleware\CartMiddleware;

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
            __DIR__.'/../config/cart.php', 'cart'
        );
    }

    /**
     * Bootstrap services.
     *
     * @return void
     */
    public function boot()
    {

        app('router')->aliasMiddleware('cart', CartMiddleware::class);

        $this->publishes([
            __DIR__.'/../config/cart.php' => config_path('cart.php'),
        ]);

        $this->loadRoutesFrom(__DIR__.'/../routes.php');
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadFactoriesFrom(__DIR__.'/../database/factories');
        $this->loadTranslationsFrom(__DIR__.'/../resources/lang', 'ecommerce');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'ecommerce');
        $this->loadViewComponentsAs('ecommerce', [
            \Marshmallow\Ecommerce\Cart\View\Components\Cart::class,
        ]);

        $this->publishes([
            __DIR__.'/../resources/lang' => resource_path('lang/vendor/ecommerce/cart'),
        ], 'translations');

        $this->publishes([
            __DIR__.'/../resources/views' => resource_path('views/vendor/ecommerce/cart'),
        ], 'views');

        $this->publishes([
            __DIR__.'/../public' => public_path('vendor/marshmallow/ecommerce/cart'),
        ], 'public');

        if ($this->app->runningInConsole()) {
            $this->commands([
                \Marshmallow\Ecommerce\Cart\Console\Commands\CleanCartsCommand::class,
            ]);
        }
    }
}
