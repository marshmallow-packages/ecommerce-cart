<?php

namespace Marshmallow\Ecommerce\Cart\Models;

use Illuminate\Database\Eloquent\Model;
use Marshmallow\Ecommerce\Cart\Models\Inquiry;

class InquiryItem extends Model
{
	protected $guarded = [];
	
    public function inquiry ()
    {
        return $this->belongsTo(Inquiry::class);
    }

    public function product ()
    {
    	return $this->belongsTo(config('cart.models.product'));
    }
}
