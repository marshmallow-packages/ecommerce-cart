<?php

declare(strict_types=1);

namespace Marshmallow\Ecommerce\Cart\Listeners;

use Illuminate\Auth\Events\Login;
use Illuminate\Database\Eloquent\Model;
use Marshmallow\Ecommerce\Cart\Events\CartMerged;
use Marshmallow\Ecommerce\Cart\Facades\Cart;
use Marshmallow\Ecommerce\Cart\Models\ShoppingCart;

/**
 * On login, reconcile the guest's session cart with the user's account.
 *
 * If the user has no open cart the guest cart simply becomes theirs. If they do
 * have one, the guest cart's lines are merged into it so nothing the visitor
 * gathered before signing in is lost.
 */
class MergeCartOnLogin
{
    public function handle(Login $event): void
    {
        /** @var Model $user */
        $user = $event->user;

        $sessionCart = Cart::get();
        $cartModel = config('cart.models.shopping_cart');

        $existing = $cartModel::latestOpenForUser($user);

        if (! $existing || $existing->is($sessionCart)) {
            $sessionCart->connectUser($user);

            return;
        }

        $existing->mergeFrom($sessionCart);
        $existing->connectUser($user);

        session()->put(ShoppingCart::SESSION_KEY, $existing->id);
        session()->put(ShoppingCart::SESSION_TOKEN_KEY, $existing->guard_token);

        event(new CartMerged($existing, $sessionCart));
    }
}
