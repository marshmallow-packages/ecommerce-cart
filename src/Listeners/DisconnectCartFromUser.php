<?php

declare(strict_types=1);

namespace Marshmallow\Ecommerce\Cart\Listeners;

use Illuminate\Auth\Events\Logout;
use Marshmallow\Ecommerce\Cart\Facades\Cart;

/**
 * On logout from the storefront guard, detach the current cart from the user
 * so the next visitor on the same session does not inherit it.
 */
class DisconnectCartFromUser
{
    public function handle(Logout $event): void
    {
        if ($event->guard !== Cart::getUserGuard()) {
            return;
        }

        Cart::get()->disconnectUser();
    }
}
