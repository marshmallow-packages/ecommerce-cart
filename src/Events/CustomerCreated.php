<?php

declare(strict_types=1);

namespace Marshmallow\Ecommerce\Cart\Events;

use Marshmallow\Ecommerce\Cart\Models\Customer;

final class CustomerCreated
{
    public function __construct(public Customer $customer) {}
}
