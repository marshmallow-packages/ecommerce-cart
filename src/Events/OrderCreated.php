<?php

namespace Marshmallow\Ecommerce\Cart\Events;

use Illuminate\Queue\SerializesModels;
use Marshmallow\Ecommerce\Cart\Models\Order;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Broadcasting\InteractsWithSockets;

class OrderCreated
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $order;

    public function __construct(Order $order)
    {
        $this->order = $order;
    }
}
