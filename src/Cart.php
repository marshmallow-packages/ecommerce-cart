<?php

declare(strict_types=1);

namespace Marshmallow\Ecommerce\Cart;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;
use Marshmallow\Ecommerce\Cart\Contracts\Purchasable;
use Marshmallow\Ecommerce\Cart\Models\ShoppingCart;

/**
 * The entry point behind the Cart facade: it resolves the current cart from the
 * session and exposes it to the request.
 */
class Cart
{
    public const REQUEST_KEY = 'cart';

    public function addToRequest(Request $request, ShoppingCart $cart): Request
    {
        $request->attributes->set(self::REQUEST_KEY, $cart);

        return $request;
    }

    public function getFromRequest(): ?ShoppingCart
    {
        $cart = request()->attributes->get(self::REQUEST_KEY);

        return $cart instanceof ShoppingCart ? $cart : null;
    }

    /**
     * Fail loudly on the first storefront request when the configured product
     * model cannot be put in a cart, instead of on the first add() deep
     * inside a checkout. A model that does not exist (yet) is left alone so
     * publishing and migrating a fresh install always work.
     */
    public function assertConfigurationIsUsable(): void
    {
        $product = config('cart.models.product');

        if (is_string($product) && class_exists($product) && ! is_subclass_of($product, Purchasable::class)) {
            throw new InvalidArgumentException(
                "The configured cart product model [{$product}] must implement ".Purchasable::class.'.',
            );
        }
    }

    public function getUserGuard(): string
    {
        return (string) (config('cart.customer_guard') ?: Auth::getDefaultDriver());
    }

    /**
     * The current cart, creating a fresh one if the session has none.
     */
    public function get(): ShoppingCart
    {
        $cartModel = config('cart.models.shopping_cart');

        return $cartModel::getBySession() ?? $cartModel::completelyNew();
    }
}
