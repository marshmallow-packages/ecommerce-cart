![alt text](https://marshmallow.dev/cdn/media/logo-red-237x46.png "marshmallow.")

# Ecommerce Cart

[![Latest Version on Packagist](https://img.shields.io/packagist/v/marshmallow/cart.svg?style=flat-square)](https://packagist.org/packages/marshmallow/cart)
[![Tests](https://img.shields.io/github/actions/workflow/status/marshmallow-packages/ecommerce-cart/tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/marshmallow-packages/ecommerce-cart/actions/workflows/tests.yml)
[![Total Downloads](https://img.shields.io/packagist/dt/marshmallow/cart.svg?style=flat-square)](https://packagist.org/packages/marshmallow/cart)

Framework-agnostic e-commerce cart, order and discount engine for Laravel. This major drops every hard dependency on an admin panel — there is no Nova (or Filament) requirement in the core — so the same cart logic powers a storefront regardless of how the shop is administered.

- A session-backed **shopping cart** with line combining, quantity handling and per-line price snapshots.
- An immutable **`Price`** value object: integer cents, VAT-inclusive canonical, with `net + vat === gross` guaranteed.
- **Discounts** (fixed amount, percentage, free shipping) with prerequisites, eligibility rules and usage limits.
- **Shipping methods** the customer picks, priced with a free-over-threshold, plus **fee lines** for payment surcharges.
- **Orders** created from a paid cart as an immutable financial record, idempotent on the cart id.
- A full **event stream**, **stock hooks**, **cart merge on login** and **abandoned-cart housekeeping**.

Requires PHP `^8.3` and Laravel `^12.0 || ^13.0`.

## Installation

Install the package via Composer:

```bash
composer require marshmallow/cart
```

Publish the config file:

```bash
php artisan vendor:publish --tag="cart-config"
```

Publish and run the migrations:

```bash
php artisan vendor:publish --tag="cart-migrations"
php artisan migrate
```

Coming from a Nova-based release? Publish the guarded upgrade migration instead and read [UPGRADE.md](UPGRADE.md):

```bash
php artisan vendor:publish --tag="cart-upgrade-migrations"
php artisan migrate
```

Register the middleware (aliased as `cart`) on your storefront routes so every request carries the current cart:

```php
Route::middleware('cart')->group(function () {
    // storefront routes
});
```

## Configuration

| Key | Default | Description |
| --- | --- | --- |
| `models` | package models | Every model the cart touches, swappable per entry. `product` must point at a model implementing `Purchasable`. |
| `currency` | `EUR` | ISO 4217 code stamped onto `Price` value objects. |
| `locale` | `nl_NL` | Locale for the money formatter. |
| `prices_include_vat` | `true` | Whether back-office prices are entered gross. The `Price` object is gross-canonical either way. |
| `default_vat_percentage` | `21.0` | Rate a discount line inherits when the cart mixes VAT rates. |
| `customer_guard` | `web` | Guard used to connect a signed-in user to the cart. |
| `middleware` | alias `cart`, no exclusions | Middleware class, alias and the request paths it should skip. |
| `listeners` | merge / disconnect | Login and logout listeners; set to `[]` to opt out. |
| `stock` | both `true` | Whether `Purchasable::isAvailableForPurchase()` runs on add and again at checkout. |
| `abandoned` | 30 / 90 days | Days until a quiet cart counts as abandoned, and until it is pruned. |
| `discount.voucher` | length 8 | Generated voucher shape. |
| `commands` | `CleanCartsCommand` | The housekeeping command class. |

## Usage

### Make your product purchasable

The cart never reaches into your product model directly. Point `config('cart.models.product')` at your model and implement the four-method contract:

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

Implement `HasPurchasableCategories` as well when you want discounts scoped to categories.

### Work with the cart

```php
use Marshmallow\Ecommerce\Cart\Facades\Cart;

$cart = Cart::get();
$cart->add($product, quantity: 2);
$cart->add($product, quantity: 1, meta: ['size' => 'L']); // meta makes it a separate line

$cart->applyDiscount($discount);   // throws DiscountException when not allowed
$cart->removeDiscount();

$cart->getSubtotal();              // product lines, gross cents
$cart->getTotalAmount();           // grand total incl. shipping, discount and fees
$cart->getTotalVatAmount();

$order = $cart->convertToOrder();  // once the cart is paid for — idempotent
```

### Prices

Every amount is an immutable, cents-based value object. The gross amount is canonical, so `net + vat === gross` always holds:

```php
use Marshmallow\Ecommerce\Cart\Support\Price;

$price = Price::fromGross(12100, 21.0);   // € 121,00 incl. 21% VAT
$price->amountExcludingVat;               // 10000
$price->vatAmount();                      // 2100

Price::fromNet(10000, 21.0);              // same price, built from the net side
$price->multiply(3);                      // line total
$price->percentage(10);                   // basis for a 10% discount
$price->format();                         // "€ 121,00" in the configured locale
```

### Shipping and fees

The customer picks a shipping method; the method prices itself against the cart, with an optional free-over-threshold (`free_from_amount`). A single fee line carries a payment surcharge and is replaced — never stacked — when the choice changes:

```php
$cart->selectShippingMethod($method);   // null clears shipping (e.g. pickup)
$cart->setFee('Toeslag VISA', Price::fromGross(150, 21.0));
$cart->setFee('Toeslag VISA', null);    // remove the surcharge again
```

### Events

Hook into the full lifecycle without touching package code:

| Event | Fires when |
| --- | --- |
| `CartCreated` | a fresh cart is minted for the session |
| `ItemAdded`, `ItemQuantityChanged`, `ItemRemoved` | product lines change |
| `DiscountApplied`, `DiscountRejected` | a voucher lands or is refused (with the reason) |
| `ShippingCalculated` | a shipping method is (re)priced for the cart |
| `CartMerged` | a guest cart folds into the user's open cart at login |
| `CustomerCreated` | a prospect is promoted to a customer |
| `OrderCreated` | the paid cart became an order |
| `CartAbandoned` | housekeeping flags a quiet cart |

### Extend the models

Every model resolves through `config('cart.models.*')`, so a host application can subclass any of them — to add multi-tenancy, wire in payment, or add its own relations:

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

The cart already exposes everything `marshmallow/payable` asks of a payable model (`getTotalAmount()`, `getPayableDescription()`, the customer getters), so payment is one trait away.

### Housekeeping

Schedule the abandoned-cart command to flag quiet carts (firing `CartAbandoned` per cart) and prune the long-expired ones:

```php
Schedule::command('ecommerce:clean-carts')->daily();
```

Dutch translations for the customer-facing discount messages ship with the package; publish them with the `cart-translations` tag to override.

## Testing

```bash
composer test
```

The suite runs on Pest with a 100% coverage gate; `composer analyse` runs PHPStan and `composer lint` runs Pint.

## Contributing

Pull requests are welcome. Please open an issue first to discuss substantial changes.

## Security Vulnerabilities

Please report security vulnerabilities by email to stef@marshmallow.dev rather than via the public issue tracker.

## Credits

- [Stef van Esch](https://github.com/stefvanesch)
- [All Contributors](https://github.com/marshmallow-packages/ecommerce-cart/contributors)

## License

The MIT License (MIT).
