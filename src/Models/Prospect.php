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
        return $this->hasOne(ShoppingCart::class);
    }

    public function country ()
    {
        return $this->belongsTo(Country::class);
    }
}
