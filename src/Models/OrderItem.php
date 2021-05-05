<?php

namespace Marshmallow\Ecommerce\Cart\Models;

use Illuminate\Database\Eloquent\Model;
use Marshmallow\Ecommerce\Cart\Traits\ItemTotals;
use Marshmallow\Ecommerce\Cart\Traits\PriceFormatter;

class OrderItem extends Model
{
    use ItemTotals;
    use PriceFormatter;

    protected $guarded = [];

    public function order()
    {
        return $this->belongsTo(config('cart.models.order'));
    }

    public function product()
    {
        return $this->belongsTo(config('cart.models.product'));
    }

    public function vatrate()
    {
        return $this->belongsTo(
            config('cart.models.vat_rate')
        );
    }

    public function currency()
    {
        return $this->belongsTo(
            config('cart.models.currency')
        );
    }
}
