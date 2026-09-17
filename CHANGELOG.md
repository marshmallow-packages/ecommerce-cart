# Changelog

All notable changes to `marshmallow/cart` are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project adheres to
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [6.1.0] - 2026-09-17

Checkout integrity release: an order is built from the snapshot a payment was
started with, the cart has an explicit confirm/reopen lifecycle, and everything
an order needs to stand on its own is frozen onto it. Requires
`marshmallow/payable ^4.3`.

### Upgrading from 6.0 — what to change in your project

Work through these in order; each one names the code you touch.

1. **Run the migrations.** `composer update marshmallow/cart marshmallow/payable`,
   then `php artisan vendor:publish --tag=cart-migrations` and `php artisan migrate`.
   The new migration is additive (columns, indexes, a backfill) and safe to
   re-run. Payable 4.3 adds `payments.payable_snapshot` through its own
   auto-loaded migration.
2. **`Purchasable::getPurchasablePrice()` gained a cart parameter.** Change
   your product model's signature to
   `getPurchasablePrice(int $quantity = 1, ?ShoppingCart $cart = null): Price`.
   Use `$cart` for customer-specific prices; ignore it otherwise.
3. **Confirm the cart before payment.** Call `$cart->confirm()` before you
   redirect to the payment provider, or let `$cart->startPayment()` do it (it
   confirms first). `confirm()` throws `EmptyCartException`,
   `PurchasableUnavailableException` or `DiscountException` while the customer
   can still act; show the message and stay on the checkout. Replace any
   `$cart->update(['confirmed_at' => now()])` / `forceFill([...])` with
   `confirm()` and `reopen()` — `confirmed_at`, `converted_at`,
   `abandoned_at`, `guard_token` and `display_id` are guarded now.
4. **Stop converting on the paid webhook yourself** (or keep doing it — it is
   idempotent). The package listens to payable's `PaymentStatusPaid` and
   creates the order from `$payment->payable_snapshot`. Disable it with
   `config('cart.payable.convert_on_paid') = false` if you must convert from
   your own listener; then call `Order::createFromSnapshot($payment->payable_snapshot, $cart, $payment)`
   rather than `convertToOrder()`, so the order reflects what was paid for.
5. **Stock and voucher problems after payment are events, not exceptions.**
   `convertToOrder()` / `createFromSnapshot()` no longer throw
   `PurchasableUnavailableException`; listen for `StockShortageDetected` and
   `DiscountInvalidAtConversion` instead (backorder, partial refund). Also
   listen for `PaymentSnapshotMismatch` (paid amount ≠ snapshot: no order was
   created) and `DuplicatePaymentDetected` (second payment for a converted
   cart: refund it).
6. **Read order facts from the snapshots.** `$order->customerSnapshot()`,
   `shippingAddressSnapshot()`, `invoiceAddressSnapshot()`,
   `shippingMethodSnapshot()` and `discountsSnapshot()` hold what was sold; the
   `*_id` columns remain as references only. Order money columns and snapshots
   are guarded against mass assignment.
7. **Discounts may book several lines.** `Discount::calculateForCart()` returns
   a `Collection<Price>` (one per VAT rate) instead of a single `Price`, and
   `DiscountApplied` now carries `int $amount` (gross cents) plus
   `Collection $prices`. `$cart->discountItems()` can contain more than one
   line per code; use `$cart->discounts()` for the applied `Discount` models.
8. **`setQuantity(0)` removes the line** (it used to clamp to 1). Adjust any
   code that relied on the clamp.
9. **Mixed currencies are refused.** Adding a line in another currency than
   the cart throws `CurrencyMismatchException`; make sure shipping methods and
   fees use the shop currency.
10. **Soft deletes are honoured** on customers, cart lines, shipping methods,
    discounts, orders and order lines. A `delete()` keeps the row; use
    `forceDelete()` where you really meant to remove it.
11. **Order statuses are guarded.** Pending → canceled/completed/refunded,
    completed → refunded, canceled → pending; anything else throws
    `InvalidOrderStatusTransitionException`. Repeating the current status is a
    no-op and `OrderRefunded` fires once.
12. **Housekeeping hard-deletes.** `ecommerce:clean-carts` now permanently
    removes expired carts with their lines and orphaned prospects; the config
    key `abandoned.fire_events` is renamed `abandoned.flag_abandoned` (the old
    key keeps working).
13. **Login/logout listeners only react to `customer_guard`.** If your
    storefront logs in through another guard, set `cart.customer_guard`.
14. **Factories moved to the package autoload.** `Discount::factory()` etc.
    work in a host application; `Order`, `OrderItem` and `ShoppingCartItem`
    no longer expose `factory()` (create them through the cart flow).
15. **Removed:** the unused `discount.voucher` config block.

### Added

- `ShoppingCart::confirm()`, `reopen()`, `isConfirmed()`, `isConverted()`,
  with `CartConfirmed` / `CartReopened` events and `CartConvertedException`.
- `Order::createFromSnapshot()`; `ShoppingCart::getPayableSnapshot()` is now a
  complete description (lines, customer, addresses with country, shipping
  method, vouchers, totals, fingerprint). `Order::payment()` relation and
  `payment_id`, `customer_snapshot`, `shipping_address_snapshot`,
  `invoice_address_snapshot`, `shipping_method_snapshot`,
  `discounts_snapshot`, `snapshot_fingerprint` columns with accessors.
- `ConvertPaidPaymentToOrder` listener on payable's `PaymentStatusPaid`;
  `config('cart.payable.convert_on_paid')` and `cart.listeners.payment_paid`.
- `ShoppingCart::startPayment()` confirms the cart first; `paymentAllowed()`
  refuses a converted cart.
- `purchasable_type` on cart and order lines: any model implementing
  `Purchasable` can be sold (morph map aware); lines with the same key but
  different types stay apart. `HasPurchasable` trait with `resolvePurchasable()`.
- `ShoppingCart::remove()`, `setQuantity()`, `clear()`, `discounts()`,
  `order()`, `withoutRecalculating()`, `recalculate()`; `Customer::carts()`.
- Events `ShippingMethodSelected`, `FeeChanged`, `OrderStatusChanged`,
  `StockShortageDetected`, `DiscountInvalidAtConversion`,
  `PaymentSnapshotMismatch`, `DuplicatePaymentDetected`.
- `Discount::byCodes()`; `converted_at` on carts; indexes on every column the
  package filters or joins on (`shopping_carts.user_id`, `(confirmed_at,
  updated_at)`, `shopping_cart_items (shopping_cart_id, signature)`,
  `order_items (type, description)`, `customers.email`, …).
- Config validation: a `cart.models.product` that does not implement
  `Purchasable` fails on the first web request instead of deep in a checkout.
- Dutch translation for "This voucher is already applied to your shopping cart."
- `pint.json`, Dependabot for Composer and Actions, PHP 8.5 in CI,
  `composer audit` in CI, this changelog.

### Changed

- Discounts are split per VAT rate (largest-remainder rounding), a free
  shipping code follows the shipping line's rate, and a scoped fixed amount
  is capped at the eligible lines. Applying a discount is transactional: a
  rejected replacement leaves the existing codes untouched.
- Stock is checked on the line's total quantity when adding or increasing,
  on every line in `confirm()`, and only reported after payment.
- Every line change touches the cart (`updated_at`), so live carts are never
  flagged or pruned; an abandoned flag is cleared on activity; an empty cart
  carries no shipping line; a converted cart is never handed back by the
  session, the middleware, `latestOpenForUser()` or the login merge.
- Line signatures include the purchasable type and are normalised on the
  key, so repeat additions keep combining after a re-save.
- Merges, price refreshes and reorders recalculate once instead of per line;
  the recalculation pass loads discounts in one query and reuses the cart
  instance on its lines.
- Cart ids are ordered UUIDs; the unsaved cart the middleware attaches
  persists itself on its first mutation, so `Cart::getFromRequest()` stays
  valid; a cart without a guard token, one the session does not hold the
  token for, or a converted one is replaced by a fresh cart.
- `recalculateDiscount()` swallows only `DiscountException`; eligible e-mails
  match case-insensitively; addresses and note are under the lock;
  `disconnectUser()` and prune batch their writes.
- Migrations publish through Laravel's `publishesMigrations()` (timestamped
  on publish). `composer.json` drops `minimum-stability: dev`.

### Fixed

- The customer's selected shipping method was replaced by the default on
  every cart change; a free-shipping discount kept the old shipping cost after
  `selectShippingMethod()`.
- Line signatures changed on the first re-save (int vs string key), so repeat
  additions stopped combining.
- Minting a cart while signed in dropped the user's customer.
- Relations without explicit foreign keys broke for differently named host
  subclasses.
- Carts from before guard tokens crashed `authorized()`.
- Canceled and refunded orders now hand voucher redemptions back.
- Once-per-customer could be bypassed by applying the code before entering
  an e-mail (re-validated in `confirm()`).

## [6.0.0] - 2026-09-12

Nova-free major. See [UPGRADE.md](UPGRADE.md) for the migration from 5.x.
