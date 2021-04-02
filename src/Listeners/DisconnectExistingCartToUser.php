<?php

namespace Marshmallow\Ecommerce\Cart\Listeners;

use Illuminate\Auth\Events\Logout;
use Marshmallow\Ecommerce\Cart\Facades\Cart;

class DisconnectExistingCartToUser
{
    /**
     * Handle the event.
     *
     * @param  \Illuminate\Auth\Events\Registered  $event
     * @return void
     */
    public function handle(Logout $event)
    {
        $cart = Cart::get();
        $cart->disconnectUser();
    }
}
