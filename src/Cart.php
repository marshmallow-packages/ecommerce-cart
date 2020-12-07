<?php

namespace Marshmallow\Ecommerce\Cart;

class Cart
{
	public function ping ()
	{
		return 'pong';
	}

    public function layouts ()
    {
        return [
            'ecommerce-product-overview' => \Marshmallow\Ecommerce\Cart\Flexible\Layouts\EcommerceProductOverviewLayout::class,
        ];
    }
}
