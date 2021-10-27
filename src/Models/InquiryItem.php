<?php

namespace Marshmallow\Ecommerce\Cart\Models;

use Marshmallow\Ecommerce\Cart\Cart;
use Illuminate\Database\Eloquent\Model;

class InquiryItem extends Model
{
    protected $guarded = [];

    public function inquiry()
    {
        return $this->belongsTo(config('cart.models.inquiry'));
    }

    public function product()
    {
        return $this->setConnection(Cart::$productConnection)
            ->belongsTo(config('cart.models.product'));
    }
}
