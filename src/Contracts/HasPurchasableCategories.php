<?php

declare(strict_types=1);

namespace Marshmallow\Ecommerce\Cart\Contracts;

/**
 * An optional companion to {@see Purchasable} for shops that scope discounts to
 * categories. A discount configured with "specific categories" only applies to
 * lines whose purchasable implements this and reports a matching category key.
 */
interface HasPurchasableCategories
{
    /**
     * The category identifiers this purchasable belongs to.
     *
     * @return array<int, int|string>
     */
    public function getPurchasableCategoryKeys(): array;
}
