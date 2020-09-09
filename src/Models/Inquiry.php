<?php

namespace Marshmallow\Ecommerce\Cart\Models;

use Illuminate\Database\Eloquent\Model;
use Marshmallow\Ecommerce\Cart\Models\InquiryItem;

class Inquiry extends Model
{
    protected $guarded = [];

    public function items ()
    {
        return $this->hasMany(InquiryItem::class);
    }

    public function getCustomerOrProspect ()
    {
        if ($this->customer) {
            return $this->customer;
        }

        return $this->prospect;
    }

    public function customer ()
    {
        return $this->belongsTo(config('cart.models.customer'));
    }
    
    public function prospect ()
    {
        return $this->belongsTo(config('cart.models.prospect'));
    }

    public function getAmountInDefaultCurrency () {
    	return 0;
    }
    public function getAmountInCustomerCurrency () {
    	return 0;
    }
    public function getVatAmountInCustomerCurrency () {
    	return 0;
    }
    public function syncToAccounting ()
    {
        if (!$this->accountable) {
            return app('accounting')->service->createInquiry($this);
        } else {
            // update?
        }
    }
}
