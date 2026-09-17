<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Marshmallow\Addressable\Models\AddressType;
use Marshmallow\Datasets\Country\Models\Country;
use Marshmallow\Ecommerce\Cart\Enums\CartItemType;
use Marshmallow\Ecommerce\Cart\Events\DiscountInvalidAtConversion;
use Marshmallow\Ecommerce\Cart\Events\DuplicatePaymentDetected;
use Marshmallow\Ecommerce\Cart\Events\OrderCreated;
use Marshmallow\Ecommerce\Cart\Models\Discount;
use Marshmallow\Ecommerce\Cart\Models\Order;
use Marshmallow\Ecommerce\Cart\Models\ShippingMethod;
use Marshmallow\Ecommerce\Cart\Models\ShoppingCart;
use Marshmallow\Ecommerce\Cart\Support\Price;
use Marshmallow\Payable\Models\Payment;
use Marshmallow\Payable\Models\PaymentProvider;
use Marshmallow\Payable\Models\PaymentType;
use Marshmallow\Payable\Payable;
use Workbench\App\Models\Product;

/**
 * A cart with a named customer, two addresses (with country), a shipping
 * method, a voucher and a fee — everything an order has to remember.
 */
function richCart(): ShoppingCart
{
    $country = Country::create(['name' => 'Nederland', 'slug' => 'nederland', 'alpha2' => 'NL', 'alpha3' => 'NLD']);
    $shippingType = AddressType::create(['type' => AddressType::SHIPPING, 'name' => 'Shipping']);
    $invoiceType = AddressType::create(['type' => AddressType::INVOICE, 'name' => 'Invoice']);
    ShippingMethod::factory()->create(['name' => 'PostNL', 'type' => 'parcel', 'price_including_vat' => 495]);

    $cart = ShoppingCart::completelyNew();
    $cart->prospect->update([
        'first_name' => 'Grace', 'last_name' => 'Hopper', 'company_name' => 'Navy', 'email' => 'grace@example.com', 'phone_number' => '0600000001', 'country_id' => $country->id,
    ]);
    $shipping = $cart->prospect->addresses()->create([
        'address_type_id' => $shippingType->id, 'first_name' => 'Grace', 'last_name' => 'Hopper',
        'address_line_1' => 'Kade 1', 'postal_code' => '3511AA', 'city' => 'Utrecht', 'country_id' => $country->id,
    ]);
    $invoice = $cart->prospect->addresses()->create([
        'address_type_id' => $invoiceType->id, 'address_line_1' => 'Postbus 9', 'postal_code' => '1000AA', 'city' => 'Amsterdam', 'country_id' => $country->id,
    ]);
    $cart->update(['shipping_address_id' => $shipping->id, 'invoice_address_id' => $invoice->id, 'note' => 'Niet bellen']);
    $cart->fresh()->add(productPriced(12100, 21.0, ['name' => 'Blauwe trui']), 2, ['size' => 'L']);
    $cart->fresh()->applyDiscount(Discount::factory()->percentage(10)->create(['discount_code' => 'TIEN']));
    $cart->fresh()->setFee('Toeslag VISA', Price::fromGross(150, 21));

    return $cart->fresh();
}

function fakePayment(ShoppingCart $cart, array $snapshot, int $total, ?int $paid = null): Payment
{
    $provider = PaymentProvider::firstOrCreate(['slug' => 'mollie'], ['name' => 'Mollie', 'type' => Payable::MOLLIE, 'active' => true]);
    $type = PaymentType::firstOrCreate(['slug' => 'ideal'], ['payment_provider_id' => $provider->id, 'name' => 'iDEAL', 'simple_checkout' => false, 'active' => true]);

    return Payment::withoutEvents(fn () => Payment::create([
        'id' => (string) Str::uuid(),
        'payable_type' => $cart->getMorphClass(),
        'payable_id' => $cart->id,
        'payment_provider_id' => $provider->id,
        'payment_type_id' => $type->id,
        'simple_checkout' => false,
        'total_amount' => $total,
        'paid_amount' => $paid ?? $total,
        'payable_snapshot' => $snapshot,
        'status' => Payment::STATUS_PAID,
        'started' => now(),
        'start_ip' => '127.0.0.1',
    ]));
}

it('freezes the customer, addresses, shipping method and vouchers onto the order', function (): void {
    $cart = richCart();

    $order = $cart->convertToOrder();

    expect($order->customerSnapshot())->toMatchArray([
        'first_name' => 'Grace', 'last_name' => 'Hopper', 'company_name' => 'Navy', 'email' => 'grace@example.com', 'phone_number' => '0600000001',
    ])
        ->and($order->shippingAddressSnapshot())->toMatchArray([
            'address_line_1' => 'Kade 1', 'postal_code' => '3511AA', 'city' => 'Utrecht', 'country_name' => 'Nederland', 'country_code' => 'NL',
        ])
        ->and($order->invoiceAddressSnapshot()['city'])->toBe('Amsterdam')
        ->and($order->shippingMethodSnapshot())->toMatchArray(['name' => 'PostNL', 'type' => 'parcel', 'price_including_vat' => 495, 'vat_percentage' => 21.0])
        ->and($order->discountsSnapshot())->toBe([['code' => 'TIEN', 'amount' => -2420]])
        ->and($order->snapshot_fingerprint)->toHaveLength(64)
        ->and($order->note)->toBe('Niet bellen')
        ->and($order->total_including_vat)->toBe(24200 + 495 - 2420 + 150);
});

it('keeps the facts when the address, customer and product change afterwards', function (): void {
    $cart = richCart();
    $order = $cart->convertToOrder();

    $cart->shippingAddress->update(['city' => 'Elders', 'address_line_1' => 'Nergens 0']);
    $order->customer->update(['first_name' => 'Iemand', 'email' => 'anders@example.com']);
    $cart->shippingMethod->update(['name' => 'Duurder', 'price_including_vat' => 995]);
    Product::query()->update(['name' => 'Hernoemd', 'price_cents' => 1]);

    $order = $order->fresh();
    expect($order->shippingAddressSnapshot()['city'])->toBe('Utrecht')
        ->and($order->customerSnapshot()['first_name'])->toBe('Grace')
        ->and($order->shippingMethodSnapshot()['name'])->toBe('PostNL')
        ->and($order->items->firstWhere('type', CartItemType::Product)->description)->toBe('Blauwe trui')
        ->and($order->items->firstWhere('type', CartItemType::Product)->price_including_vat)->toBe(12100)
        ->and($order->total_including_vat)->toBe(22425);
});

it('builds the order from the snapshot, not from the cart as it is now', function (): void {
    $cart = checkoutReadyCart(1000);
    $snapshot = $cart->getPayableSnapshot();

    $cart->add(productPriced(5000), 3); // the cart moved on after the snapshot

    $order = Order::createFromSnapshot($snapshot, $cart->fresh());

    expect($order->items)->toHaveCount(1)
        ->and($order->total_including_vat)->toBe(1000)
        ->and($order->snapshot_fingerprint)->toBe($snapshot['fingerprint'])
        ->and($cart->fresh()->isConverted())->toBeTrue();
});

it('converts from the snapshot of the payment that settled, even after the cart was reopened and changed', function (): void {
    $cart = checkoutReadyCart(1000);
    $cart->confirm();
    $first = fakePayment($cart, $cart->fresh()->getPayableSnapshot(), 1000);

    $cart->fresh()->reopen();
    $cart->fresh()->add(productPriced(4000), 1); // now 5000

    $order = Order::createFromSnapshot($first->payable_snapshot, $cart->fresh(), $first);

    expect($order->total_including_vat)->toBe(1000)
        ->and($order->payment_id)->toBe($first->id)
        ->and($order->payment->is($first))->toBeTrue()
        ->and($cart->fresh()->getTotalAmount())->toBe(5000)
        ->and($cart->fresh()->isConverted())->toBeTrue();
});

it('returns the existing order and flags a second payment as a duplicate', function (): void {
    Event::fake([DuplicatePaymentDetected::class, OrderCreated::class]);
    $cart = checkoutReadyCart(1000);
    $snapshot = $cart->getPayableSnapshot();
    $first = fakePayment($cart, $snapshot, 1000);
    $second = fakePayment($cart, $snapshot, 1000);

    $order = Order::createFromSnapshot($snapshot, $cart, $first);
    $again = Order::createFromSnapshot($snapshot, $cart->fresh(), $second);
    $retry = Order::createFromSnapshot($snapshot, $cart->fresh(), $first);

    expect($again->is($order))->toBeTrue()
        ->and($retry->is($order))->toBeTrue()
        ->and(Order::count())->toBe(1);
    Event::assertDispatchedTimes(OrderCreated::class, 1);
    Event::assertDispatched(DuplicatePaymentDetected::class, fn (DuplicatePaymentDetected $e): bool => $e->order->is($order) && $e->payment->is($second));
    Event::assertDispatchedTimes(DuplicatePaymentDetected::class, 1);
});

it('keeps a voucher that stopped qualifying after payment and reports it', function (): void {
    Event::fake([DiscountInvalidAtConversion::class]);
    $discount = Discount::factory()->create(['discount_code' => 'LAATSTE', 'total_usage_limit' => 1, 'fixed_amount' => 100]);
    $cart = checkoutReadyCart(10000);
    $cart->applyDiscount($discount);
    $snapshot = $cart->fresh()->getPayableSnapshot();

    // Someone else took the last redemption while this payment was in flight.
    session()->forget(ShoppingCart::SESSION_KEY);
    $other = checkoutReadyCart(10000);
    $other->applyDiscount($discount->fresh());
    $other->fresh()->convertToOrder();

    $order = Order::createFromSnapshot($snapshot, $cart->fresh());

    expect($order->discount_including_vat)->toBe(-100)
        ->and($order->total_including_vat)->toBe(9900);
    Event::assertDispatched(DiscountInvalidAtConversion::class, fn (DiscountInvalidAtConversion $e): bool => $e->order->is($order)
        && $e->code === 'LAATSTE'
        && str_contains($e->reason, 'full capacity'));
});

it('accepts a first-generation snapshot without net amounts or totals', function (): void {
    $cart = checkoutReadyCart(12100);
    $legacy = [
        'cart_id' => $cart->id,
        'display_id' => $cart->display_id,
        'total_amount' => 12100,
        'total_vat_amount' => 2100,
        'lines' => [[
            'description' => 'Oude regel', 'type' => 'PRODUCT', 'quantity' => 1,
            'unit_amount' => 12100, 'total_amount' => 12100, 'vat_percentage' => 21.0, 'currency' => 'EUR',
        ]],
    ];

    $order = Order::createFromSnapshot($legacy, $cart);

    expect($order->total_including_vat)->toBe(12100)
        ->and($order->total_excluding_vat)->toBe(10000)
        ->and($order->items->sole()->purchasable_type)->toBeNull()
        ->and($order->customerSnapshot())->toBe([]);
});

it('converts a cart directly from a snapshot taken on the spot', function (): void {
    $cart = checkoutReadyCart(1000);

    $order = Order::createFromShoppingCart($cart);

    expect($order->total_including_vat)->toBe(1000)
        ->and($cart->fresh()->isConverted())->toBeTrue();
});

it('keeps a voucher whose discount was deleted after payment without reporting it', function (): void {
    Event::fake([DiscountInvalidAtConversion::class]);
    $discount = Discount::factory()->create(['discount_code' => 'WEG', 'fixed_amount' => 100]);
    $cart = checkoutReadyCart(10000);
    $cart->applyDiscount($discount);
    $snapshot = $cart->fresh()->getPayableSnapshot();
    $discount->forceDelete();

    $order = Order::createFromSnapshot($snapshot, $cart->fresh());

    expect($order->discount_including_vat)->toBe(-100);
    Event::assertNotDispatched(DiscountInvalidAtConversion::class);
});

it('converts a line whose purchasable class no longer exists without a shortage report', function (): void {
    Event::fake([Marshmallow\Ecommerce\Cart\Events\StockShortageDetected::class]);
    $cart = checkoutReadyCart(1000);
    $snapshot = $cart->getPayableSnapshot();
    $snapshot['lines'][0]['purchasable_type'] = 'App\\Models\\Gone';

    $order = Order::createFromSnapshot($snapshot, $cart);

    expect($order->items->sole()->purchasable_type)->toBe('App\\Models\\Gone')
        ->and($order->items->sole()->resolvePurchasable())->toBeNull();
    Event::assertNotDispatched(Marshmallow\Ecommerce\Cart\Events\StockShortageDetected::class);
});

it('stamps the cart as confirmed and converted in one go', function (): void {
    $cart = checkoutReadyCart();

    $cart->convertToOrder();

    $cart = $cart->fresh();
    expect($cart->confirmed_at)->not->toBeNull()
        ->and($cart->converted_at)->not->toBeNull()
        ->and($cart->order->cart->is($cart))->toBeTrue();
});

it('describes itself completely in the payable snapshot', function (): void {
    $cart = richCart();

    $snapshot = $cart->getPayableSnapshot();

    expect($snapshot)->toHaveKeys(['version', 'cart_id', 'display_id', 'currency', 'customer', 'shipping_address', 'invoice_address', 'shipping_method', 'discounts', 'lines', 'totals', 'total_amount', 'fingerprint', 'created_at'])
        ->and($snapshot['version'])->toBe(2)
        ->and($snapshot['lines'])->toHaveCount(4)
        ->and($snapshot['lines'][0])->toHaveKeys(['id', 'purchasable_type', 'purchasable_id', 'unit_amount_excluding_vat', 'custom_price'])
        ->and($snapshot['totals']['total'])->toBe($snapshot['total_amount'])
        ->and($snapshot['fingerprint'])->toBe(ShoppingCart::fingerprintOf($snapshot));

    $again = $cart->fresh()->getPayableSnapshot();
    expect($again['fingerprint'])->toBe($snapshot['fingerprint']);

    $cart->fresh()->add(productPriced(1), 1);
    expect($cart->fresh()->getPayableSnapshot()['fingerprint'])->not->toBe($snapshot['fingerprint']);
});

it('ignores order money columns and snapshots coming in through mass assignment', function (): void {
    $order = checkoutReadyCart(1000)->convertToOrder();

    $order->update(['total_including_vat' => 1, 'status' => 'REFUNDED', 'customer_snapshot' => ['x' => 1], 'note' => 'ok']);

    $order = $order->fresh();
    expect($order->total_including_vat)->toBe(1000)
        ->and($order->isPending())->toBeTrue()
        ->and($order->customerSnapshot())->not->toBe(['x' => 1])
        ->and($order->note)->toBe('ok');
});
