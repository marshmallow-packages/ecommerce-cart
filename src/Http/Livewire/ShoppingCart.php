<?php

namespace Marshmallow\Ecommerce\Cart\Http\Livewire;

use Livewire\Component;

class ShoppingCart extends Component
{

    protected $listeners = ['productAdded' => 'updateShoppingCart'];

    public $count = 0;

    public function updateShoppingCart()
    {
        $this->count++;
    }

    public function render()
    {
        return view('ecommerce::livewire.shopping-cart');
    }
}
