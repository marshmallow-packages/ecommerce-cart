<?php

namespace Marshmallow\Ecommerce\Cart\Jobs;

use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Marshmallow\Ecommerce\Cart\Models\ShoppingCart;

class ProcessInquiryRequest implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $cart;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct(ShoppingCart $cart)
    {
        $this->cart = $cart;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        // Make an inquiry of the shopping cart
        
        $inquiry = $this->cart->convertToInquiry();
        
        
        // mail('stef@marshmallow.dev', 'process inquiry', 'Henk');
        // throw new Exception("Error Processing Request", 1);
    }

    public function failed(Exception $exception)
    {
        // Send user notification of failure, etc...
    }
}
