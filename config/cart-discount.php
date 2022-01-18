<?php

return [
    'voucher' => [
        'min_length' => 8,
        'exclude_rules' => ['Symbols', 'Lowercase', 'Similar'],
    ],
    'default' => [
        'vat_rate' => env('DISCOUNT_DEFAULT_VAT_RATE_ID', 3),
        'currency' => env('DISCOUNT_DEFAULT_CURRENCY_ID', 1),
    ],
];
