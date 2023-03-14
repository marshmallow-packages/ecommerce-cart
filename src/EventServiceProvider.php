<?php

namespace Marshmallow\Ecommerce\Cart;

use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Marshmallow\Ecommerce\Cart\Listeners\ConnectExistingCartToUser;
use Marshmallow\Ecommerce\Cart\Listeners\DisconnectExistingCartToUser;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

class EventServiceProvider extends ServiceProvider
{
    protected $listen = [];

    public function __construct(...$params)
    {
        parent::__construct(...$params);

        $this->listen = [
            Login::class => $this->getLoginListeners(),
            Logout::class => $this->getLogoutListeners(),
        ];
    }

    /**
     * Register any events for your application.
     *
     * @return void
     */
    public function boot()
    {
        parent::boot();
    }

    protected function getLoginListeners()
    {
        if (config('cart.listeners.login') !== null) {
            return config('cart.listeners.login');
        }

        return [
            ConnectExistingCartToUser::class,
        ];
    }

    protected function getLogoutListeners()
    {
        if (config('cart.listeners.logout') !== null) {
            return config('cart.listeners.logout');
        }

        return [
            DisconnectExistingCartToUser::class,
        ];
    }
}
