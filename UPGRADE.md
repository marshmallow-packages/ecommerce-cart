# Upgrading to the Nova-free major

This is a major, breaking release over every Nova-based version (up to and including 5.x). It removes the dependency on Laravel Nova and the priceable/products packages from the core, replaces the priceable-backed pricing with a self-contained `Price` value object, and moves the admin resources out into a separate companion package.

## Before you start

- This major requires PHP `^8.3` and Laravel `^12 || ^13`.
- Nova resources are no longer shipped. Keep them by installing the companion `ecommerce-cart-nova` package, or rebuild them in your own admin panel.
- Take a database backup. The upgrade migration drops columns.

## 1. Dependencies

The core no longer requires `laravel/nova`, `marshmallow/nova-flexible`, `marshmallow/products` or `marshmallow/priceable`. Remove them from your `composer.json` if nothing else uses them.

## 2. Make your product `Purchasable`

Where the Nova releases hard-typed `Marshmallow\Product\Models\Product`, this major asks only for the `Purchasable` contract. Implement it on your product model and point `config('cart.models.product')` at it. See the README for the four methods.

## 3. Prices are now cents

The `Price` value object is integer-cents and VAT-inclusive-canonical. Anywhere you built a priceable `Price`, build a cart `Price` instead:

```php
use Marshmallow\Ecommerce\Cart\Support\Price;

Price::fromGross($cents, $vatPercentage, 'EUR'); // from a VAT-inclusive amount
Price::fromNet($cents, $vatPercentage, 'EUR');   // from a VAT-exclusive amount
```

## 4. Run the schema upgrade

The upgrade migration converts existing item rows: it adds `vat_percentage`, `currency`, `purchasable_id`, `meta` (and `signature` on cart items), backfills them from the old `vatrate_id` / `currency_id` / `product_id` foreign keys, and then drops those columns along with `display_price` and the cart's `hashed_ip_address`.

```bash
php artisan vendor:publish --tag=cart-upgrade-migrations
php artisan migrate
```

The backfill runs in chunks and every step is guarded with `Schema::hasColumn`, so it is safe to re-run.

## 5. API renames

| Before | After |
| --- | --- |
| `ShoppingCart::addDiscount()` (returned a message string on failure) | `ShoppingCart::applyDiscount()` (throws `DiscountException`) |
| `Discount::isAllowed()` | `Discount::assertAllowedOn()` |
| `Discount::calculateDiscountFromCart()` | `Discount::calculateForCart()` |
| `Order::createUniqueFromShoppingCart()` | `Order::createFromShoppingCart()` |
| string type constants (`TYPE_PRODUCT`, …) | `CartItemType` enum |
| string order statuses | `OrderStatus` enum |
| `scopeVisable()` (typo) | `scopeVisible()` |

## 6. Behavioural changes to be aware of

- **Cart authorisation** now uses a random per-session `guard_token` instead of a hashed IP address. Carts created before this release have no token; the middleware simply issues a fresh cart for them, which is safe because carts are ephemeral.
- **Order conversion** copies the cart's actual shipping method and real discount totals (previously hardcoded), wraps the write in a transaction, and no longer deletes the prospect — it stamps `converted_at` instead.
- **Login** now merges a guest cart into the user's existing open cart rather than overwriting it.
- **`Inquiry` / `InquiryItem` were removed.** If you relied on them, keep them in your application.
