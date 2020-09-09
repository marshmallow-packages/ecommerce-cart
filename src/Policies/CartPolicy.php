<?php

namespace Marshmallow\Ecommerce\Cart\Policies;

use App\User;
use Marshmallow\Ecommerce\Cart\Models\ShoppingCart;
use Illuminate\Auth\Access\HandlesAuthorization;

class CartPolicy
{
    use HandlesAuthorization;

    /**
     * Determine whether the user can view any support tickets.
     *
     * @param  \App\User  $user
     * @return mixed
     */
    public function viewAny(User $user)
    {
        return true;
    }

    /**
     * Determine whether the user can view the support ticket.
     *
     * @param  \App\User  $user
     * @param  \Marshmallow\Ecommerce\Cart\Models\ShoppingCart  $cart
     * @return mixed
     */
    public function view(User $user, ShoppingCart $cart)
    {
        return true;
    }

    /**
     * Determine whether the user can create support tickets.
     *
     * @param  \App\User  $user
     * @return mixed
     */
    public function create(User $user)
    {
        return true;
    }

    /**
     * Determine whether the user can update the support ticket.
     *
     * @param  \App\User  $user
     * @param  \Marshmallow\Ecommerce\Cart\Models\ShoppingCart  $cart
     * @return mixed
     */
    public function update(User $user, ShoppingCart $cart)
    {
        return true;
    }

    /**
     * Determine whether the user can delete the support ticket.
     *
     * @param  \App\User  $user
     * @param  \Marshmallow\Ecommerce\Cart\Models\ShoppingCart  $cart
     * @return mixed
     */
    public function delete(User $user, ShoppingCart $cart)
    {
        return true;
    }

    /**
     * Determine whether the user can restore the support ticket.
     *
     * @param  \App\User  $user
     * @param  \Marshmallow\Ecommerce\Cart\Models\ShoppingCart  $cart
     * @return mixed
     */
    public function restore(User $user, ShoppingCart $cart)
    {
        return true;
    }

    /**
     * Determine whether the user can permanently delete the support ticket.
     *
     * @param  \App\User  $user
     * @param  \Marshmallow\Ecommerce\Cart\Models\ShoppingCart  $cart
     * @return mixed
     */
    public function forceDelete(User $user, ShoppingCart $cart)
    {
        return true;
    }
}
