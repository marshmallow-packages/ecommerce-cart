<?php

namespace Marshmallow\Ecommerce\Cart\Models;

use Illuminate\Database\Eloquent\Model;
use Marshmallow\Ecommerce\Cart\Models\ShoppingCart;
use Marshmallow\Datasets\Country\Models\Country;

class Prospect extends Model
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
