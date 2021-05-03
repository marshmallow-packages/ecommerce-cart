<?php

namespace Marshmallow\Ecommerce\Cart\Events;

use Illuminate\Queue\SerializesModels;
use Illuminate\Foundation\Events\Dispatchable;
use Marshmallow\Ecommerce\Cart\Models\Customer;
use Illuminate\Broadcasting\InteractsWithSockets;

class CustomerCreated
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $customer;

    public function __construct(Customer $customer)
    {
        $this->customer = $customer;
    }
}
