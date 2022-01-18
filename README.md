![alt text](https://marshmallow.dev/cdn/media/logo-red-237x46.png "marshmallow.")

# Ecommerce Shopping Cart

[![Version](https://img.shields.io/packagist/v/marshmallow/cart)](https://github.com/marshmallow-packages/ecommerce-cart)
[![Issues](https://img.shields.io/github/issues/marshmallow-packages/ecommerce-cart)](https://github.com/marshmallow-packages/ecommerce-cart)
[![Licence](https://img.shields.io/github/license/marshmallow-packages/ecommerce-cart)](https://github.com/marshmallow-packages/ecommerce-cart)

This package contains all the logic you need to make use of a shopping cart in your Laravel application. It also contains all the Nova resources you need to manage your store. We use this package at Marshmallow for a lot of customers and add new functionalities when ever we need them. If you wish to use this, please do so and let us know if you have any issues.

## Installation

### Composer

You can install this package via the following composer command.

```
composer require marshmallow/cart
```

### Migrate

You need to run the migration from this package to create all the tables we need to do some ecommerce magic.

```bash
php artisan migrate
```

### Middleware

Please add the following middle ware to your web group to make sure the cart is available on every route. If you wish to include this middleware to a select set of route you can do so.

```php
protected $middlewareGroups = [
    'web' => [
        // ...
        \Marshmallow\Ecommerce\Cart\Http\Middleware\CartMiddleware::class,
    ],
];
```

### Nova

Run the commands below to publish all the Nova resources that you need to manage all the ecommerce stuff.

```bash
php artisan marshmallow:resource Product Product
php artisan marshmallow:resource ProductCategory Product
php artisan marshmallow:resource Supplier Product
php artisan marshmallow:resource Price Priceable
php artisan marshmallow:resource VatRate Priceable
php artisan marshmallow:resource Currency Priceable
php artisan marshmallow:resource Prospect Ecommerce\\Cart
php artisan marshmallow:resource ShoppingCart Ecommerce\\Cart
php artisan marshmallow:resource Customer Ecommerce\\Cart
php artisan marshmallow:resource ShippingMethod Ecommerce\\Cart
php artisan marshmallow:resource ShippingMethodCondition Ecommerce\\Cart
php artisan marshmallow:resource Order Ecommerce\\Cart
php artisan marshmallow:resource OrderItem Ecommerce\\Cart
php artisan marshmallow:resource Discount Ecommerce\\Cart
php artisan marshmallow:resource Route Seoable
```

### Seed tables

We have created seeders for ecommerce site in the Netherlands. If you are running a dutch shop, you can run these seeders. If not, don't run these. Just create your own via the Nova resources you've just created.

```bash
php artisan db:seed --class=Marshmallow\\Priceable\\Seeders\\CurrencySeeder
php artisan db:seed --class=Marshmallow\\Priceable\\Seeders\\VatRatesSeeder
```

### Envoirment file

Make sure you set the `CURRENCY` value in you `.env` file to match the currency you are using.

```env
CURRENCY=eur
```

## Events

This package triggers a set of events which you can listen to in your application if you wish to do so.

| Name            | Description                                                  |
| --------------- | ------------------------------------------------------------ |
| CustomerCreated | This will be triggered once a new customer has been created. |
| OrderCreated    | This will be triggerd once a new order has been created.     |

# Discounts

## Setup

To use the discount module, you first need to make sure you have run all the `migrations`.

### Create the Nova resource

You need to publish the Nova resource to be able to create new discount code's in Nova. Run the command below.

```bash
php artisan marshmallow:resource Discount Ecommerce\\Cart
```

### Publish the new config

There is a new config file that handles defaults for the discount functionalities. Run the command below to publish the new config file.

```bash
php artisan vendor:publish --tag="ecommerce-discount-config"
```

| Key                   | Description                                            |
| --------------------- | ------------------------------------------------------ |
| voucher.min_length    | The minimum required length of a voucher code          |
| voucher.exclude_rules | The exlusion rules for the code generator              |
| default.vat_rate      | The default `vat rate` we need to use for the discount |
| default.currency      | The default `currency` we need to use for the discount |

## Usage

### Adding a discount

To add the discount to a shopping cart, you need to create your own route/endpoint to handle this. You can use the example code below to active the discount. If all is oke, the `$response` will be empty. If something went wrong this method will return an error message containing the reason why we couldn't add the discount to the cart.

```php
use Marshmallow\Ecommerce\Cart\Facades\Cart;
use Marshmallow\Ecommerce\Cart\Models\Discount;

$discount = Discount::byCode(
    request()->discount
);

$response = Cart::get()->addDiscount($discount);
```

### Deleting a discount

If you made the customer to be able to delete an activated discount from the shopping cart, you will again have to create your own route/endpoint for this. You can then use the example code below to remove the discount from the card.

```php
use Marshmallow\Ecommerce\Cart\Facades\Cart;

Cart::get()->deleteDiscount();
```

# Cart methods

With the introduction of the discount methods you might need to update the methods that are used in your shopping cart to display cart totals. Please see the new methods below.

```php
$cart->getTotalAmountWithoutShippingAndDiscount();
$cart->getTotalAmountWithoutShippingAndDiscountAndWithoutVat();
$cart->getDiscountAmount();
$cart->getDiscountAmountWithoutVat();
```

# Cart methods

```php
/**
 * These are helper functions to get cart totals.
 */
$cart->getTotalAmountWithoutShipping();
$cart->getTotalAmountWithoutShippingAndWithoutVat();
$cart->getShippingAmount();
$cart->getShippingAmountWithoutVat();
$cart->getTotalAmount();
$cart->getTotalAmountWithoutVat();
$cart->getTotalVatAmount();
$cart->getTotalAmountWithoutShippingAndDiscount();
$cart->getTotalAmountWithoutShippingAndDiscountAndWithoutVat();
$cart->getDiscountAmount();
$cart->getDiscountAmountWithoutVat();

/**
 * You can format all the methods above to get a string with currency.
 */
$cart->getFormatted('getTotalAmount');

/**
 * Extra helpers
 */
$cart->productCount();
$cart->getItemsWithoutShipping();
$cart->getItemsWithoutDiscount();
$cart->getDiscountItems();
$cart->getItemsWithoutDiscountAndShipping();
$cart->getOnlyProductItems();
```

# Item methods

```php
$item->setQuantity(4);
$item->increaseQuantity();
$item->decreaseQuantity();

// Amount helpers
$item->getUnitAmount();
$item->getUnitAmountWithVat();
$item->getUnitAmountWithoutVat();
$item->getUnitVatAmount();
$item->getTotalAmount();
$item->getTotalAmountWithVat();
$item->getTotalAmountWithoutVat();
$item->getTotalVatAmount();

// Formatted
$item->getFormatted('getTotalAmount');
```

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information what has changed recently.

## Testing

```bash
composer test
```

## Security

If you discover any security related issues, please email stef@marshmallow.dev instead of using the issue tracker.

## Credits

-   [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
