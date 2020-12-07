<?php

namespace Marshmallow\Ecommerce\Cart\Models;

use Illuminate\Database\Eloquent\Model;

class Customer extends Model
{
    protected $guarded = [];

    public function cart ()
    {
        return $this->hasOne(
            config('cart.models.shopping_cart')
        );
    }

    public function country ()
    {
        return $this->belongsTo(
            config('cart.models.country')
        );
    }
}
