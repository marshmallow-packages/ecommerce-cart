<?php

namespace Marshmallow\Ecommerce\Cart\Http\Livewire;

use Livewire\Component;

class ProductToCart extends Component
{
    public function increment()
    {
        $this->emit('productAdded');
    }

    public function render()
    {
        return view('ecommerce::livewire.product-to-cart');
    }
}
