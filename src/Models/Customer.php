<?php

namespace Marshmallow\Ecommerce\Cart\Models;

use Illuminate\Database\Eloquent\Model;
use Marshmallow\Addressable\Traits\Addressable;

class Customer extends Model
{
    use Addressable;

    protected $guarded = [];

    public function getFullName()
    {
        return trim("{$this->first_name} {$this->last_name}");
    }

    public function cart()
    {
        return $this->hasOne(
            config('cart.models.shopping_cart')
        );
    }

    public function country()
    {
        return $this->belongsTo(
            config('cart.models.country')
        );
    }

    public function orders()
    {
        return $this->hasMany(
            config('cart.models.order'),
        );
    }
}
