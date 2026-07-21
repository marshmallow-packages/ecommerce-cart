<?php

declare(strict_types=1);

namespace Marshmallow\Ecommerce\Cart\Concerns;

use Illuminate\Database\Eloquent\Collection;
use Marshmallow\Ecommerce\Cart\Contracts\CartLine;
use Marshmallow\Ecommerce\Cart\Enums\CartItemType;
use Marshmallow\Ecommerce\Cart\Models\ShoppingCartItem;

/**
 * Cart/order level money accessors.
 *
 * All figures are summed from the already-loaded `items` relation, filtered in
 * memory, rather than from a fresh query per accessor. Line cents are summed
 * as-is and never re-rounded: each line was rounded once when it was created,
 * so the grand total is the exact sum of what the customer was shown per line.
 * Discount lines carry a negative gross amount, so summing every line yields
 * the payable total directly.
 */
trait CalculatesTotals
{
    public function productCount(): int
    {
        return $this->visibleItems()
            ->where('type', CartItemType::Product)
            ->sum('quantity');
    }

    public function getSubtotal(): int
    {
        return $this->sumGross($this->productItems());
    }

    public function getSubtotalWithoutVat(): int
    {
        return $this->sumNet($this->productItems());
    }

    public function getShippingAmount(): int
    {
        return $this->sumGross($this->shippingItems());
    }

    public function getShippingAmountWithoutVat(): int
    {
        return $this->sumNet($this->shippingItems());
    }

    public function getShippingVatAmount(): int
    {
        return $this->getShippingAmount() - $this->getShippingAmountWithoutVat();
    }

    public function getDiscountAmount(): int
    {
        return $this->sumGross($this->discountItems());
    }

    public function getDiscountAmountWithoutVat(): int
    {
        return $this->sumNet($this->discountItems());
    }

    public function getDiscountVatAmount(): int
    {
        return $this->getDiscountAmount() - $this->getDiscountAmountWithoutVat();
    }

    public function getFeeAmount(): int
    {
        return $this->sumGross($this->feeItems());
    }

    public function getFeeAmountWithoutVat(): int
    {
        return $this->sumNet($this->feeItems());
    }

    public function getFeeVatAmount(): int
    {
        return $this->getFeeAmount() - $this->getFeeAmountWithoutVat();
    }

    public function getTotalAmount(): int
    {
        return $this->sumGross($this->loadedItems());
    }

    public function getTotalAmountWithoutVat(): int
    {
        return $this->sumNet($this->loadedItems());
    }

    public function getTotalVatAmount(): int
    {
        return $this->getTotalAmount() - $this->getTotalAmountWithoutVat();
    }

    /**
     * Product lines only, without shipping or discount.
     *
     * @return Collection<int, ShoppingCartItem>
     */
    public function productItems(): Collection
    {
        return $this->loadedItems()->where('type', CartItemType::Product)->values();
    }

    /**
     * @return Collection<int, ShoppingCartItem>
     */
    public function shippingItems(): Collection
    {
        return $this->loadedItems()->where('type', CartItemType::Shipping)->values();
    }

    /**
     * @return Collection<int, ShoppingCartItem>
     */
    public function discountItems(): Collection
    {
        return $this->loadedItems()->where('type', CartItemType::Discount)->values();
    }

    /**
     * @return Collection<int, ShoppingCartItem>
     */
    public function feeItems(): Collection
    {
        return $this->loadedItems()->where('type', CartItemType::Fee)->values();
    }

    /**
     * The visible lines only, for display in a cart summary.
     *
     * @return Collection<int, ShoppingCartItem>
     */
    public function visibleItems(): Collection
    {
        return $this->loadedItems()->where('visible_in_cart', true)->values();
    }

    /**
     * @return Collection<int, ShoppingCartItem>
     */
    protected function loadedItems(): Collection
    {
        /** @var Collection<int, ShoppingCartItem> $items */
        $items = $this->items;

        return $items;
    }

    /**
     * @param  Collection<int, ShoppingCartItem>  $items
     */
    private function sumGross(Collection $items): int
    {
        return (int) $items->sum(fn (CartLine $item): int => $item->getTotalAmount());
    }

    /**
     * @param  Collection<int, ShoppingCartItem>  $items
     */
    private function sumNet(Collection $items): int
    {
        return (int) $items->sum(fn (CartLine $item): int => $item->getTotalAmountWithoutVat());
    }
}
