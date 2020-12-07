<?php

namespace Marshmallow\Ecommerce\Cart;

use Livewire\Livewire;
use Illuminate\Support\Facades\Blade;
use Marshmallow\Ecommerce\Cart\Http\Livewire\ShoppingCart;
use Marshmallow\Ecommerce\Cart\Http\Livewire\ProductToCart;
use Illuminate\Support\ServiceProvider as BaseServiceProvider;
use Marshmallow\Ecommerce\Cart\Http\Middleware\CartMiddleware;
use Marshmallow\Ecommerce\Cart\Console\Commands\DemoShopCommand;
use Marshmallow\Ecommerce\Cart\Console\Commands\CleanCartsCommand;
use Marshmallow\Ecommerce\Cart\Console\Commands\EcommercePublishCommand;

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

        $this->loadResources();
        $this->registerEcommerceBladeComponents();
        $this->registerEcommerceLivewireComponents();
        $this->registerPublishebles();
        $this->loadCommands();
    }

    protected function registerEcommerceBladeComponents()
    {
        Blade::component('ecommerce-cart', \Marshmallow\Ecommerce\Cart\View\Components\Cart::class);
        Blade::component('ecommerce-main-menu', \Marshmallow\Ecommerce\Cart\View\Components\EcommerceMainMenuComponent::class);
    }

    protected function registerEcommerceLivewireComponents()
    {
        Livewire::component('shopping-cart', ShoppingCart::class);
        Livewire::component('product-to-cart', ProductToCart::class);
    }

    protected function loadCommands()
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                CleanCartsCommand::class,
                EcommercePublishCommand::class,
                DemoShopCommand::class,
            ]);
        }
    }

    protected function registerPublishebles()
    {
        $this->publishes([
            __DIR__.'/../config/cart.php' => config_path('cart.php'),
            __DIR__.'/../config/nova-menu.php' => config_path('nova-menu.php'),
        ], 'ecommerce-config');

        $this->publishes([
            __DIR__.'/../resources/lang' => resource_path('lang/vendor/ecommerce/cart'),
        ], 'ecommerce-translations');

        $this->publishes([
            __DIR__.'/../resources/views' => resource_path('views/vendor/ecommerce/cart'),
        ], 'ecommerce-views');

        $this->publishes([
            __DIR__.'/../public' => public_path('vendor/marshmallow/ecommerce'),
        ], 'ecommerce-assets');
    }

    protected function loadResources()
    {
        $this->loadRoutesFrom(__DIR__.'/../routes.php');
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadFactoriesFrom(__DIR__.'/../database/factories');
        $this->loadTranslationsFrom(__DIR__.'/../resources/lang', 'ecommerce');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'ecommerce');
    }
}
