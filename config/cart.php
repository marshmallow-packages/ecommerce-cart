<?php

declare(strict_types=1);

use App\Models\Product;
use App\Models\User;
use Marshmallow\Addressable\Models\Address;
use Marshmallow\Datasets\Country\Models\Country;
use Marshmallow\Ecommerce\Cart\Console\Commands\CleanCartsCommand;
use Marshmallow\Ecommerce\Cart\Http\Middleware\CartMiddleware;
use Marshmallow\Ecommerce\Cart\Listeners\DisconnectCartFromUser;
use Marshmallow\Ecommerce\Cart\Listeners\MergeCartOnLogin;
use Marshmallow\Ecommerce\Cart\Models\Customer;
use Marshmallow\Ecommerce\Cart\Models\Discount;
use Marshmallow\Ecommerce\Cart\Models\Order;
use Marshmallow\Ecommerce\Cart\Models\OrderItem;
use Marshmallow\Ecommerce\Cart\Models\Prospect;
use Marshmallow\Ecommerce\Cart\Models\ShippingMethod;
use Marshmallow\Ecommerce\Cart\Models\ShippingMethodCondition;
use Marshmallow\Ecommerce\Cart\Models\ShoppingCart;
use Marshmallow\Ecommerce\Cart\Models\ShoppingCartItem;

return [

    /*
    |--------------------------------------------------------------------------
    | Models
    |--------------------------------------------------------------------------
    |
    | Every model the cart touches is resolved through this map, so a host
    | application can swap in its own subclass without editing the package.
    | The `product` entry must point at a model implementing the Purchasable
    | contract; `address` and `country` are supplied by the host or the
    | marshmallow/addressable and dataset-country packages.
    |
    */
    'models' => [
        'user' => User::class,
        'product' => Product::class,
        'prospect' => Prospect::class,
        'customer' => Customer::class,
        'discount' => Discount::class,
        'shopping_cart' => ShoppingCart::class,
        'shopping_cart_item' => ShoppingCartItem::class,
        'order' => Order::class,
        'order_item' => OrderItem::class,
        'shipping_method' => ShippingMethod::class,
        'shipping_method_condition' => ShippingMethodCondition::class,
        'address' => Address::class,
        'country' => Country::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Currency & VAT
    |--------------------------------------------------------------------------
    |
    | `currency` is the default ISO 4217 code stamped onto Price value objects.
    | `locale` drives the money formatter. `prices_include_vat` records whether
    | prices entered in the back office are gross or net; the Price object is
    | always gross-canonical regardless. `default_vat_percentage` is the rate a
    | discount line inherits when it cannot borrow one from the cart.
    |
    */
    'currency' => env('CART_CURRENCY', 'EUR'),
    'locale' => env('CART_LOCALE', 'nl_NL'),
    'prices_include_vat' => true,
    'default_vat_percentage' => 21.0,

    /*
    |--------------------------------------------------------------------------
    | Cart identification
    |--------------------------------------------------------------------------
    |
    | The guard the cart uses to connect a signed-in user, and the request
    | paths on which the cart middleware should do nothing (e.g. an admin
    | panel that manages its own carts).
    |
    */
    'customer_guard' => 'web',

    'middleware' => [
        'alias' => 'cart',
        'class' => CartMiddleware::class,
        'excluded_paths' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Login / logout listeners
    |--------------------------------------------------------------------------
    |
    | Merge the guest cart into the user's existing open cart on login, and
    | disconnect it on logout. Set either to an empty array to opt out.
    |
    */
    'listeners' => [
        'login' => [MergeCartOnLogin::class],
        'logout' => [DisconnectCartFromUser::class],
    ],

    /*
    |--------------------------------------------------------------------------
    | Stock
    |--------------------------------------------------------------------------
    |
    | Whether Purchasable::isAvailableForPurchase() is consulted when an item
    | is added and again per line before an order is created.
    |
    */
    'stock' => [
        'check_on_add' => true,
        'check_on_checkout' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Abandoned carts
    |--------------------------------------------------------------------------
    |
    | A cart with no activity for `expires_after_days` counts as abandoned and
    | fires CartAbandoned; one untouched for `delete_after_days` is pruned by
    | the ecommerce:clean-carts command.
    |
    */
    'abandoned' => [
        'expires_after_days' => 30,
        'delete_after_days' => 90,
        'fire_events' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Discounts
    |--------------------------------------------------------------------------
    */
    'discount' => [
        'voucher' => [
            'min_length' => 8,
            'exclude_rules' => ['Symbols', 'Lowercase', 'Similar'],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Console commands
    |--------------------------------------------------------------------------
    */
    'commands' => [
        'clean_carts' => CleanCartsCommand::class,
    ],
];
