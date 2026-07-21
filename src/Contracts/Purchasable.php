<?php

declare(strict_types=1);

namespace Marshmallow\Ecommerce\Cart\Contracts;

use Marshmallow\Ecommerce\Cart\Models\ShoppingCart;
use Marshmallow\Ecommerce\Cart\Support\Price;

/**
 * Something that can be added to a cart.
 *
 * The host application implements this on whatever model represents a sellable
 * thing (a product, a subscription, a gift card). The cart never reaches into
 * that model directly; it asks only for the four values below, so the package
 * carries no dependency on any particular product package.
 */
interface Purchasable
{
    /**
     * The stable identifier stored on the cart line (`purchasable_id`).
     */
    public function getPurchasableKey(): int|string;

    /**
     * The human-readable description snapshotted onto the cart line.
     */
    public function getPurchasableName(): string;

    /**
     * The unit price snapshotted onto the cart line at the moment of adding.
     */
    public function getPurchasablePrice(): Price;

    /**
     * Whether the given quantity may be added to (or kept in) the cart.
     *
     * Called when an item is added and again per line before an order is
     * created. Returning false raises a PurchasableUnavailableException. The
     * cart is passed so implementations can account for quantities already in
     * it; it is null when no cart context is available yet.
     */
    public function isAvailableForPurchase(int $quantity, ?ShoppingCart $cart = null): bool;
}
