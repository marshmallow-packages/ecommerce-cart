<?php

namespace Marshmallow\Ecommerce\Cart\Models;

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
        return $this->belongsTo(config('cart.models.product'));
    }
}
