<?php

namespace Marshmallow\Ecommerce\Cart\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Marshmallow\Addressable\Traits\Addressable;
use Marshmallow\Ecommerce\Cart\Events\CustomerCreated;

class Prospect extends Model
{
    use Addressable;
    use SoftDeletes;

    protected $guarded = [];

    public function getFullName()
    {
        return trim("{$this->first_name} {$this->last_name}");
    }

    /**
     * Publics
     */
    public function convertToCustomer()
    {
        if ($customer = config('cart.models.customer')::where('prospect_id', $this->id)->first()) {
            return $customer;
        }
        $customer = config('cart.models.customer')::where('email', $this->email)->whereNotNull('email')->first();
        if (!$customer) {
            $customer = config('cart.models.customer')::create([
                'first_name' => $this->first_name,
                'last_name' => $this->last_name,
                'company_name' => $this->company_name,
                'address' => $this->address,
                'country_id' => $this->country_id,
                'email' => $this->email,
                'phone_number' => $this->phone_number,
                'prospect_id' => $this->id,
            ]);

            event(new CustomerCreated($customer));
        }

        return $customer;
    }

    public function getCustomer()
    {
        if ($customer = config('cart.models.customer')::where('prospect_id', $this->id)->first()) {
            return $customer;
        }
        $customer = config('cart.models.customer')::where('email', $this->email)->whereNotNull('email')->first();

        return $customer;
    }

    /**
     * Relationships
     */
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
}
