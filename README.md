![alt text](https://cdn.marshmallow-office.com/media/images/logo/marshmallow.transparent.red.png "marshmallow.")

# Ecommerce Shopping Cart
Lorem ipsum dolor sit amet, consectetur adipiscing elit, sed do eiusmod tempor incididunt ut labore et dolore magna aliqua. Ut enim ad minim veniam, quis nostrud exercitation ullamco laboris nisi ut aliquip ex ea commodo consequat. Duis aute irure dolor in reprehenderit in voluptate velit esse cillum dolore eu fugiat nulla pariatur. Excepteur sint occaecat cupidatat non proident, sunt in culpa qui officia deserunt mollit anim id est laborum.

### Installatie
```
composer require marshmallow/ecommerce-cart
```

### Usage
```
protected $routeMiddleware = [
	...
    'cart' => \ App\Http\Middleware\NeedsShoppingCart::class,
];
```


# Install Nova
```bash
php artisan marshmallow:resource Product Product
php artisan marshmallow:resource ProductCategory Product
php artisan marshmallow:resource Price Priceable
php artisan marshmallow:resource VatRate Priceable
php artisan marshmallow:resource Currency Priceable
php artisan marshmallow:resource Prospect Ecommerce\\Cart
php artisan marshmallow:resource ShoppingCart Ecommerce\\Cart
php artisan marshmallow:resource Customer Ecommerce\\Cart
php artisan marshmallow:resource Page Pages
php artisan marshmallow:resource Route Seoable
```

# Routes
Add this to you `routes/web.php`.
```php
\Marshmallow\Seoable\Seoable::routes();
\Marshmallow\Pages\Facades\Page::loadRoutes();
```

# Seed tables
```bash
php artisan db:seed --class=Marshmallow\\Priceable\\Seeders\\CurrencySeeder
php artisan db:seed --class=Marshmallow\\Priceable\\Seeders\\VatRatesSeeder
```

# Envoirment file
```env
CASHIER_CURRENCY=eur
```

# Layouts
Load the layouts in your `flexible.php` config.
```
'layouts' => [
    function () {
        return \Marshmallow\Ecommerce\Cart\Facades\Cart::layouts();
    }
],
```

# Nova Service Provider
Register the tool with Nova in the tools() method of the NovaServiceProvider:
```php
// in app/Providers/NovaServiceProvider.php

public function tools()
{
    return [
        // ...
        new \OptimistDigital\MenuBuilder\MenuBuilder,
    ];
}
```

# Menu linkables
Add the following linkables to your `config/nova-menu.php` file.
```php
'linkable_models' => [
    \Marshmallow\Pages\Classes\PageLinkable::class, // If you already have a link to your page resource, you dont need this one.
],
```


- - -

Copyright (c) 2020 marshmallow
