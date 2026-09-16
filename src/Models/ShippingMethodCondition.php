<?php

declare(strict_types=1);

namespace Marshmallow\Ecommerce\Cart\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A subtotal band that makes its shipping method applicable. Amounts are gross
 * cents; a null maximum means "no upper bound".
 *
 * @property int $minimum_amount_including_vat
 * @property int|null $maximum_amount_including_vat
 */
class ShippingMethodCondition extends Model
{
    use HasFactory;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'minimum_amount_including_vat' => 'integer',
            'maximum_amount_including_vat' => 'integer',
        ];
    }

    public function matches(int $subtotal): bool
    {
        if ($subtotal < $this->minimum_amount_including_vat) {
            return false;
        }

        return $this->maximum_amount_including_vat === null
            || $subtotal <= $this->maximum_amount_including_vat;
    }

    public function shippingMethod(): BelongsTo
    {
        return $this->belongsTo(config('cart.models.shipping_method'));
    }
}
