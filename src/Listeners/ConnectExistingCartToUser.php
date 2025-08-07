<?php

namespace Marshmallow\Ecommerce\Cart\Listeners;

use Illuminate\Auth\Events\Login;
use Marshmallow\Ecommerce\Cart\Facades\Cart;

class ConnectExistingCartToUser
{
    /**
     * Handle the event.
     *
     * @param  \Illuminate\Auth\Events\Login  $event
     * @return void
     */
    public function handle(Login $event)
    {
        $cart = Cart::get();
        $cart->connectUser($event->user);
    }
}
