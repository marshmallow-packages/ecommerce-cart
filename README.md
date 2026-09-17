![alt text](https://marshmallow.dev/cdn/media/logo-red-237x46.png "marshmallow.")

# Ecommerce Cart

[![Latest Version on Packagist](https://img.shields.io/packagist/v/marshmallow/cart.svg?style=flat-square)](https://packagist.org/packages/marshmallow/cart)
[![Tests](https://img.shields.io/github/actions/workflow/status/marshmallow-packages/ecommerce-cart/tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/marshmallow-packages/ecommerce-cart/actions/workflows/tests.yml)
[![Total Downloads](https://img.shields.io/packagist/dt/marshmallow/cart.svg?style=flat-square)](https://packagist.org/packages/marshmallow/cart)

A cart, order and discount engine for Laravel storefronts. The core has no admin-panel dependency (no Nova, no Filament); it builds on `marshmallow/payable` for payments and `marshmallow/addressable` for addresses.

- A session-backed **shopping cart** with line combining, quantity handling, per-line price snapshots and an explicit **confirm → pay → convert** lifecycle.
- An immutable **`Price`** value object: integer cents, VAT-inclusive canonical, with `net + vat === gross` guaranteed.
- **Discounts** (fixed amount, percentage, free shipping) with prerequisites, eligibility rules, usage limits and stacking, booked per VAT rate.
- **Shipping methods** the customer picks, priced with a free-over-threshold, plus **fee lines** for payment surcharges.
- **Orders** built from the snapshot a payment was started with — lines, customer, addresses, shipping method and vouchers frozen onto the order — idempotent on the cart id, with guarded status transitions.
- A full **event stream**, **stock hooks**, **cart merge on login** and **abandoned-cart housekeeping**.

Requires PHP `^8.3`, Laravel `^12.0 || ^13.0` and `marshmallow/payable ^4.3`.

## Installation

```bash
composer require marshmallow/cart
php artisan vendor:publish --tag="cart-config"
php artisan vendor:publish --tag="cart-migrations"
php artisan migrate
```

Coming from a Nova-based release (5.x)? Publish the guarded upgrade migration as well and read [UPGRADE.md](UPGRADE.md):

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

Inside those routes the cart is available as `Cart::getFromRequest()` (or `$request->attributes->get('cart')`). A visitor without a cart gets an unsaved one; it persists itself on the first `add()`, so nothing is written for bots and bounces.

## Configuration

| Key | Default | Description |
| --- | --- | --- |
| `models` | package models | Every model the cart touches, swappable per entry with a subclass. `product` is the fallback model for lines without a purchasable type and must implement `Purchasable`. |
| `currency` | `EUR` | ISO 4217 code stamped onto `Price` value objects. A cart holds one currency. |
| `locale` | `nl_NL` | Locale for the money formatter. |
| `prices_include_vat` | `true` | Whether back-office prices are entered gross. The `Price` object is gross-canonical either way. |
| `default_vat_percentage` | `21.0` | Rate a zero-valued discount line falls back to. |
| `customer_guard` | `web` | Guard whose logins and logouts touch the cart. |
| `middleware` | alias `cart`, no exclusions | Middleware class, alias and the request paths it should skip. |
| `listeners` | merge / disconnect / convert | Login, logout and `payment_paid` listeners; set any to `[]` to opt out. |
| `payable.convert_on_paid` | `true` | Create the order from the payment snapshot when payable reports a payment paid. |
| `stock` | both `true` | `check_on_add`: consult `isAvailableForPurchase()` when a line is added or grows. `check_on_checkout`: check every line in `confirm()` and report shortages after payment. |
| `abandoned` | 30 / 90 days | Days until a quiet cart is flagged as abandoned (`flag_abandoned`), and until it is permanently pruned. |
| `commands` | `CleanCartsCommand` | The housekeeping command class. |

## Usage

### Make your product purchasable

The cart never reaches into your product model directly. Implement the four-method contract on any Eloquent model — a product, a subscription, a gift card; the cart stores the model's morph type next to its key, so one cart can hold lines from several models:

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

    public function getPurchasablePrice(int $quantity = 1, ?ShoppingCart $cart = null): Price
    {
        return Price::fromGross($this->price_cents, 21.0, 'EUR');
    }

    public function isAvailableForPurchase(int $quantity, ?ShoppingCart $cart = null): bool
    {
        return $this->stock >= $quantity;
    }
}
```

Implement `HasPurchasableCategories` as well when you want discounts scoped to categories. Point `config('cart.models.product')` at your main product model; it is the fallback for lines written before purchasable types existed.

`getPurchasablePrice()` is your pricing hook: `$quantity` for tiered prices (the cart re-asks whenever a line's quantity changes) and `$cart` for customer-specific price lists. `isAvailableForPurchase()` receives the line's total quantity when a line is added or grows, and again per line in `confirm()`. Lines added with a caller-chosen price (`addCustom()`) are never repriced or stock-checked.

### Work with the cart

```php
use Marshmallow\Ecommerce\Cart\Facades\Cart;

$cart = Cart::get();
$line = $cart->add($product, quantity: 2);
$cart->add($product, quantity: 1, meta: ['size' => 'L']); // meta makes it a separate line

$cart->setQuantity($line, 5);        // 0 removes the line
$cart->remove($line);
$cart->clear();

$cart->applyDiscount($discount);      // throws DiscountException when not allowed
$cart->removeDiscount('CODE');        // one code; no argument clears them all
$cart->discounts();                   // the applied Discount models

$cart->getSubtotal();                 // product lines, gross cents
$cart->getTotalAmount();              // grand total incl. shipping, discount and fees
$cart->getTotalVatAmount();
```

Every line in a cart shares one currency; a line in another currency throws `CurrencyMismatchException`.

Discounts stack when every code involved is marked `is_combinable`; a percentage code then compounds over the already-discounted subtotal. A non-combinable code replaces whatever is applied (and vice versa), the same code is refused twice, and every applied code is re-evaluated on each cart change — a code that no longer qualifies drops off. Applying a code is transactional, so a rejected replacement leaves the existing codes untouched. A discount books one negative line per VAT rate it spans (pro rata, cent-exact), so the VAT on the discount mirrors the VAT on what it discounts; a free-shipping code follows the shipping line's rate.

### Checkout: confirm, pay, convert

```php
$cart->confirm();   // freeze the cart for payment; throws while the customer can still act
$cart->reopen();    // after a failed or canceled payment
```

`confirm()` refuses an empty cart (`EmptyCartException`), a line whose purchasable can no longer supply its quantity (`PurchasableUnavailableException`) and a voucher that no longer qualifies with what is known by now — usage limits, once-per-customer with the customer's e-mail (`DiscountException`). It throws and leaves the cart open, so the storefront can show the message. Once confirmed, every mutation throws `CartLockedException`; addresses and the note are covered too.

With `marshmallow/payable` the cart *is* the payable:

```php
$url = $cart->startPayment($paymentType); // confirms first, then hands the customer to the provider
```

Payable freezes `$cart->getPayableSnapshot()` onto the payment the moment it starts. When the provider reports the payment paid, the package's `ConvertPaidPaymentToOrder` listener creates the order **from that snapshot** — never from the live cart. A cart that was reopened and changed after the payment started cannot leak into the order; a paid amount that does not match the snapshot creates no order and fires `PaymentSnapshotMismatch`; a second payment for an already converted cart returns the existing order and fires `DuplicatePaymentDetected` so you can refund it.

Without payable, convert yourself once you know the payment settled:

```php
$order = $cart->convertToOrder(expectedTotalAmount: $paidCents); // idempotent on the cart id
$order = Order::createFromSnapshot($snapshot, $cart, $payment);   // from a snapshot you kept
```

Conversion happens after money changed hands, so it never throws for a product that sold out or a voucher that stopped qualifying in the meantime: the order is created as paid for and `StockShortageDetected` / `DiscountInvalidAtConversion` tell you to follow up. The cart is stamped `converted_at` and closed for good (`CartConvertedException`); the session, the middleware and the login merge hand out a fresh cart from then on.

### What an order remembers

An order stands on its own. Besides its lines it carries `customerSnapshot()`, `shippingAddressSnapshot()`, `invoiceAddressSnapshot()` (every address column plus country name and code), `shippingMethodSnapshot()`, `discountsSnapshot()` and a `snapshot_fingerprint`. Edit the address book, rename the product or change the shipping method a year later: the order still says what was sold. The `*_id` columns remain as references; the money columns and snapshots are guarded against mass assignment.

```php
$order->markAsCompleted();   // OrderStatusChanged
$order->markAsRefunded();    // OrderStatus::Refunded + OrderRefunded (once)
$cart = $order->toNewCart(); // re-order: fresh cart at current prices, skipping what vanished or sold out
```

Status changes are guarded: pending → canceled, completed or refunded; completed → refunded; canceled → pending; a refund is final. Anything else throws `InvalidOrderStatusTransitionException`; repeating the current status is a no-op. A canceled or refunded order hands its voucher redemptions back.

An open cart can re-ask every purchasable for its current price, for example when a customer returns to a cart that sat overnight:

```php
$changed = $cart->refreshPrices(); // repriced lines; fires ItemPriceChanged per line
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

The customer picks a shipping method; the method prices itself against the cart, with an optional free-over-threshold (`free_from_amount`, measured against the product subtotal). Which methods apply is decided by their `ShippingMethodCondition` bands (inclusive subtotal ranges; a method without conditions applies to any cart), `valid_from` / `valid_till`, and `sort` (lowest wins as the default). The picked method stays selected through cart changes for as long as it is still active and applicable; then the default takes over. A single fee line carries a payment surcharge and is replaced, never stacked:

```php
$cart->selectShippingMethod($method);   // null clears shipping (e.g. pickup); ShippingMethodSelected
$cart->setFee('Toeslag VISA', Price::fromGross(150, 21));
$cart->setFee('Toeslag VISA', null);    // remove the surcharge again; FeeChanged
```

Override `hasExcludedShipping()` in a cart subclass to rule shipping out for a cart (a download-only order, say). An empty cart never carries a shipping line.

### Events

Hook into the full lifecycle without touching package code:

| Event | Fires when |
| --- | --- |
| `CartCreated` | a fresh cart is minted for the session |
| `ItemAdded`, `ItemQuantityChanged`, `ItemRemoved` | product lines change |
| `ItemPriceChanged` | `refreshPrices()` or a tier crossing repriced a line |
| `DiscountApplied`, `DiscountRejected` | a voucher lands (with its total and per-rate lines) or is refused (with the reason) |
| `ShippingCalculated` | a shipping method is (re)priced for the cart |
| `ShippingMethodSelected`, `FeeChanged` | the customer picked a method / a fee was set or cleared |
| `CartConfirmed`, `CartReopened` | the cart froze for payment / was taken back |
| `CartMerged` | a guest cart's product lines fold into the user's open cart at login; the guest cart is soft-deleted with its lines |
| `CustomerCreated` | a prospect is promoted to a customer |
| `OrderCreated` | the paid cart became an order |
| `StockShortageDetected`, `DiscountInvalidAtConversion` | an order was created although a line sold out / a voucher stopped qualifying after payment |
| `PaymentSnapshotMismatch`, `DuplicatePaymentDetected` | a paid amount did not match its snapshot (no order) / a second payment settled for a converted cart |
| `OrderStatusChanged`, `OrderRefunded` | an order moved status / was refunded |
| `CartAbandoned` | housekeeping flags a quiet cart |

### Extend the models

Every model resolves through `config('cart.models.*')`, so a host application can subclass any of them — to add multi-tenancy, extra relations or your own logic. Subclasses may carry any name; relations use explicit foreign keys.

```php
// config/cart.php
'shopping_cart' => \App\Models\Shop\ShoppingCart::class,
```

```php
namespace App\Models\Shop;

class ShoppingCart extends \Marshmallow\Ecommerce\Cart\Models\ShoppingCart
{
    public function hasExcludedShipping(): bool
    {
        return $this->productItems()->every(fn ($line) => $line->meta['digital'] ?? false);
    }
}
```

Login and logout listeners react only to `config('cart.customer_guard')`; an admin signing into another guard in the same browser never touches the customer's cart.

### Housekeeping

Schedule the abandoned-cart command to flag quiet carts (firing `CartAbandoned` per cart) and permanently prune the long-expired ones, lines and orphaned prospects included. A flagged cart that sees activity again is unflagged; every line change counts as activity. Confirmed and converted carts are never touched.

```php
Schedule::command('ecommerce:clean-carts')->daily();
```

Dutch translations for the customer-facing discount messages ship with the package; publish them with the `cart-translations` tag to override.

## Testing

```bash
composer test
```

The suite runs on Pest with a 100% coverage gate; `composer analyse` runs PHPStan and `composer lint` runs Pint.

## Changelog

See [CHANGELOG.md](CHANGELOG.md); upgrade notes live in [UPGRADE.md](UPGRADE.md).

## Contributing

Pull requests are welcome. Please open an issue first to discuss substantial changes.

## Security Vulnerabilities

Please report security vulnerabilities by email to stef@marshmallow.dev rather than via the public issue tracker.

## Credits

- [Stef van Esch](https://github.com/stefvanesch)
- [All Contributors](https://github.com/marshmallow-packages/ecommerce-cart/contributors)

## License

The MIT License (MIT).
