<?php

declare(strict_types=1);

namespace Marshmallow\Ecommerce\Cart\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Marshmallow\Ecommerce\Cart\Concerns\CalculatesItemTotals;
use Marshmallow\Ecommerce\Cart\Concerns\HasPurchasable;
use Marshmallow\Ecommerce\Cart\Contracts\CartLine;
use Marshmallow\Ecommerce\Cart\Enums\CartItemType;

/**
 * An immutable copy of a cart line, taken from the snapshot an order is
 * created from.
 *
 * @property int $order_id
 * @property int|null $shopping_cart_item_id
 * @property string|null $purchasable_type
 * @property int|string|null $purchasable_id
 * @property string $description
 * @property CartItemType $type
 * @property int $quantity
 * @property int $price_excluding_vat
 * @property int $price_including_vat
 * @property int $vat_amount
 * @property float $vat_percentage
 * @property string $currency
 * @property array<string, mixed>|null $meta
 * @property bool $visible_in_cart
 */
class OrderItem extends Model implements CartLine
{
    use CalculatesItemTotals;
    use HasPurchasable;
    use SoftDeletes;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => CartItemType::class,
            'meta' => 'array',
            'quantity' => 'integer',
            'price_excluding_vat' => 'integer',
            'price_including_vat' => 'integer',
            'vat_amount' => 'integer',
            'vat_percentage' => 'float',
            'visible_in_cart' => 'boolean',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(config('cart.models.order'));
    }
}
