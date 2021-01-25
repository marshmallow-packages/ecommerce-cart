<?php

/**
 * All classes can be overrules by your own custom classes.
 * The classes that you overrule should extend the original version
 * for the cart system to work properly.
 */
return [

    /**
     * Models
     */
	'models' => [
		'product' => \Marshmallow\Product\Models\Product::class,
        'country' => \Marshmallow\Datasets\Country\Models\Country::class,
		'prospect' => \Marshmallow\Ecommerce\Cart\Models\Prospect::class,
		'customer' => \Marshmallow\Ecommerce\Cart\Models\Customer::class,
        'shopping_cart' => \Marshmallow\Ecommerce\Cart\Models\ShoppingCart::class,
        'shopping_cart_item' => \Marshmallow\Ecommerce\Cart\Models\ShoppingCartItem::class,
        'inquiry' => \Marshmallow\Ecommerce\Cart\Models\Inquiry::class,
        'inquiry_item' => \Marshmallow\Ecommerce\Cart\Models\InquiryItem::class,
	],

    /**
     * Nova resources
     */
    'nova' => [
        'resources' => [
            'prospect' => \Marshmallow\Ecommerce\Cart\Nova\Prospect::class,
            'customer' => \Marshmallow\Ecommerce\Cart\Nova\Customer::class,
            'country' => \Marshmallow\Datasets\Country\Nova\Country::class,
            'shopping_cart' => \Marshmallow\Ecommerce\Cart\Nova\ShoppingCart::class,
        ],
    ],

    /**
     * Jobs
     */
    'jobs' => [
        'process_inquiry_request' => \Marshmallow\Ecommerce\Cart\Jobs\ProcessInquiryRequest::class,
    ],

    /**
     * View classes
     */
    'view' => [
        'components' => [
            'cart' => \Marshmallow\Ecommerce\Cart\View\Components\Cart::class,
            'ecommerce_main_menu_component' => \Marshmallow\Ecommerce\Cart\View\Components\EcommerceMainMenuComponent::class,
        ],
    ],

    /**
     * HTTP classes
     */
    'http' => [
        'middleware' => [
            'cart' => \Marshmallow\Ecommerce\Cart\Http\Middleware\CartMiddleware::class,
        ],
        'livewire' => [
            'shopping_cart' => \Marshmallow\Ecommerce\Cart\Http\Livewire\ShoppingCart::class,
            'product_to_cart' => \Marshmallow\Ecommerce\Cart\Http\Livewire\ProductToCart::class,
        ],
    ],

    /**
     * Commands used by the cart package
     */
    'commands' => [
        'clean_carts_command' => \Marshmallow\Ecommerce\Cart\Console\Commands\CleanCartsCommand::class,
    ],

    /**
     * Override the guard to use to connect
     * a loggedin user to the shopping carts
     */
    'customer_guard' => 'web',
];
