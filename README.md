# Ecommerce Cart

A framework-agnostic shopping cart, order and discount engine for Laravel. This major drops every hard dependency on an admin panel — there is no Nova (or Filament) requirement in the core — so the same cart logic powers a storefront regardless of how the shop is administered.

## What it gives you

- A session-backed **shopping cart** with line combining, quantity handling and per-line price snapshots.
- An immutable **`Price`** value object: integer cents, VAT-inclusive canonical, with `net + vat === gross` guaranteed.
- **Discounts** (fixed amount, percentage, free shipping) with prerequisites, eligibility rules and usage limits.
- **Shipping methods** selected from a cart's subtotal through configurable condition bands.
- **Orders** created from a paid cart as an immutable financial record, idempotent on the cart id.
- A rich **event stream** (`CartCreated`, `ItemAdded`, `ItemRemoved`, `ItemQuantityChanged`, `DiscountApplied`, `DiscountRejected`, `ShippingCalculated`, `CartMerged`, `OrderCreated`, `CustomerCreated`, `CartAbandoned`).
- **Cart merge on login**, **stock hooks** and **abandoned-cart** housekeeping out of the box.

## Requirements

- PHP `^8.3`
- Laravel `^12.0 || ^13.0`

## Installation

```bash
composer require marshmallow/cart
```

Publish and run the migrations:

```bash
php artisan vendor:publish --tag=cart-migrations
php artisan migrate
```

Publish the config if you want to change the defaults:

```bash
php artisan vendor:publish --tag=cart-config
```

## Making a model purchasable

The cart never reaches into your product model directly. Point `config('cart.models.product')` at your model and implement `Purchasable`:

```php
use Marshmallow\Ecommerce\Cart\Contracts\Purchasable;
use Marshmallow\Ecommerce\Cart\Models\ShoppingCart;
use Marshmallow\Ecommerce\Cart\Support\Price;

class Product extends Model implements Purchasable
{
    public function getPurchasableKey(): int|string
    {
        return $this->getKey();
    }

    public function getPurchasableName(): string
    {
        return $this->name;
    }

    public function getPurchasablePrice(): Price
    {
        return Price::fromGross($this->price_cents, 21.0, 'EUR');
    }

    public function isAvailableForPurchase(int $quantity, ?ShoppingCart $cart = null): bool
    {
        return $this->stock >= $quantity;
    }
}
```

## Using the cart

```php
use Marshmallow\Ecommerce\Cart\Facades\Cart;

$cart = Cart::get();
$cart->add($product, quantity: 2);
$cart->add($product, quantity: 1, meta: ['size' => 'L']); // a separate line

$cart->applyDiscount($discount);        // throws DiscountException when not allowed
$cart->getTotalAmount();                // grand total in cents, incl. shipping and discount

$order = $cart->convertToOrder();       // once the cart is paid for
```

Register the middleware (aliased as `cart`) on your storefront routes to resolve the current cart per request:

```php
Route::middleware('cart')->group(function () {
    // storefront routes
});
```

## Extending the models

Every model is resolved through `config('cart.models.*')`, so a host application can subclass any of them — to add multi-tenancy, wire in payment, or add its own relations — and point the config at the subclass:

```php
// config/cart.php
'shopping_cart' => \App\Models\Shop\ShoppingCart::class,
```

```php
namespace App\Models\Shop;

use Marshmallow\Payable\Traits\Payable;
use Marshmallow\Payable\Traits\PayableWithItems;

class ShoppingCart extends \Marshmallow\Ecommerce\Cart\Models\ShoppingCart
{
    use Payable;
    use PayableWithItems;
}
```

## Housekeeping

Schedule the abandoned-cart command to flag and prune stale carts:

```php
Schedule::command('ecommerce:clean-carts')->daily();
```

## Upgrading from a Nova-based release

See [UPGRADE.md](UPGRADE.md).

## License

MIT.
