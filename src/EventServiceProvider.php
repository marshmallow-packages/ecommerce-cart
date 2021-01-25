<?php

namespace Marshmallow\Ecommerce\Cart;

use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Marshmallow\Ecommerce\Cart\Listeners\ConnectExistingCartToUser;
use Marshmallow\Ecommerce\Cart\Listeners\DisconnectExistingCartToUser;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

class EventServiceProvider extends ServiceProvider
{
    protected $listen = [
        Login::class => [
            ConnectExistingCartToUser::class,
        ],
        Logout::class => [
            DisconnectExistingCartToUser::class,
        ],
    ];

    /**
     * Register any events for your application.
     *
     * @return void
     */
    public function boot()
    {
        parent::boot();
    }
}
