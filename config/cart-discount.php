<?php

return [
    'voucher' => [
        'min_ength' => 8,
        'exclude_rules' => ['Symbols', 'Lowercase', 'Similar'],
    ],
    'default' => [
        'vat_rate' => 6,
        'currency' => 2,
    ],

    'data_connectors' => [
        'products' => \Marshmallow\Ecommerce\Cart\Helpers\DiscountProductSelector::class,
        'product_categories' => \Marshmallow\Ecommerce\Cart\Helpers\DiscountProductCategorySelector::class,
        'customers' => \Marshmallow\Ecommerce\Cart\Helpers\DiscountCustomerSelector::class,
    ],
];
