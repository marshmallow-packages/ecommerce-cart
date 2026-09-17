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
 * carries no dependency on any particular product package. Any Eloquent model
 * may implement it: the cart stores the model's morph type next to its key.
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
     * The unit price snapshotted onto the cart line at the moment of adding,
     * for the given quantity and cart. Tiered pricing lives here: an
     * implementation may return a lower unit price once the quantity reaches
     * a volume break. The cart is passed so customer-specific price lists can
     * look at who is buying; it is null when no cart context is available.
     * The cart re-asks whenever the line's quantity changes.
     */
    public function getPurchasablePrice(int $quantity = 1, ?ShoppingCart $cart = null): Price;

    /**
     * Whether the given quantity may be added to (or kept in) the cart.
     *
     * Called with the line's total quantity when an item is added or its
     * quantity grows, and again per line when the cart is confirmed for
     * payment. Returning false raises a PurchasableUnavailableException. The
     * cart is passed so implementations can account for what is already in
     * it; it is null when no cart context is available yet.
     */
    public function isAvailableForPurchase(int $quantity, ?ShoppingCart $cart = null): bool;
}
