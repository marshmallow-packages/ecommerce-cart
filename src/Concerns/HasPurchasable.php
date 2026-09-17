<?php

declare(strict_types=1);

namespace Marshmallow\Ecommerce\Cart\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Marshmallow\Ecommerce\Cart\Contracts\Purchasable;

/**
 * The purchasable behind a cart or order line.
 *
 * @property string|null $purchasable_type
 * @property int|string|null $purchasable_id
 */
trait HasPurchasable
{
    /**
     * Any model may be a purchasable; the type column says which. A line
     * without a type (written before 6.1) falls back to the configured
     * product model in {@see resolvePurchasable()}.
     */
    public function purchasable(): MorphTo
    {
        return $this->morphTo('purchasable', 'purchasable_type', 'purchasable_id');
    }

    /**
     * Resolve the live purchasable model behind this line, if it still exists.
     */
    public function resolvePurchasable(): ?Purchasable
    {
        if ($this->purchasable_id === null) {
            return null;
        }

        if ($this->purchasable_type === null) {
            $purchasable = config('cart.models.product')::find($this->purchasable_id);

            return $purchasable instanceof Purchasable ? $purchasable : null;
        }

        // A purchasable that is not an Eloquent model (or whose class is gone)
        // cannot be loaded through the relation; it simply no longer resolves.
        $class = Model::getActualClassNameForMorph($this->purchasable_type);

        if (! is_string($class) || ! is_subclass_of($class, Model::class)) {
            return null;
        }

        $purchasable = $this->purchasable;

        return $purchasable instanceof Purchasable ? $purchasable : null;
    }
}
