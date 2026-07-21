<?php

declare(strict_types=1);

use Marshmallow\Addressable\Models\AddressType;
use Marshmallow\Ecommerce\Cart\Models\Customer;
use Marshmallow\Ecommerce\Cart\Models\ShoppingCart;
use Workbench\App\Models\Product;
use Workbench\App\Models\User;

it('exposes the customer contact details for payment', function (): void {
    $cart = ShoppingCart::completelyNew();
    $cart->prospect->update([
        'first_name' => 'Grace',
        'last_name' => 'Hopper',
        'email' => 'grace@example.com',
        'phone_number' => '0600000000',
    ]);
    $cart = $cart->fresh();

    expect($cart->getCustomerName())->toBe('Grace Hopper')
        ->and($cart->getCustomerEmail())->toBe('grace@example.com')
        ->and($cart->getCustomerPhonenumber())->toBe('0600000000')
        ->and($cart->getPayableDescription())->toBe('Order #'.$cart->display_id);
});

it('returns a null name when the customer has no name', function (): void {
    $cart = ShoppingCart::completelyNew();

    expect($cart->getCustomerName())->toBeNull();
});

it('links an existing customer to the cart when the prospect maps to one', function (): void {
    $cart = ShoppingCart::completelyNew();
    Customer::factory()->create(['prospect_id' => $cart->prospect_id]);

    $cart->addCustomerIfExists();

    expect($cart->fresh()->customer_id)->not->toBeNull();
});

it('leaves the customer id alone when one is already set', function (): void {
    $customer = Customer::factory()->create();
    $cart = ShoppingCart::completelyNew();
    $cart->update(['customer_id' => $customer->id]);

    $cart->addCustomerIfExists();

    expect($cart->fresh()->customer_id)->toBe($customer->id);
});

it('connects shipping and invoice addresses', function (): void {
    $type = AddressType::create(['type' => AddressType::SHIPPING, 'name' => 'Shipping']);
    $cart = ShoppingCart::completelyNew();
    $shipping = $cart->prospect->addresses()->create(['address_type_id' => $type->id, 'city' => 'Utrecht']);
    $invoice = $cart->prospect->addresses()->create(['address_type_id' => $type->id, 'city' => 'Amsterdam']);

    $cart->connectShippingAddress($shipping);
    $cart->connectInvoiceAddress($invoice);
    $cart = $cart->fresh();

    expect($cart->hasShippingAddress())->toBeTrue()
        ->and($cart->hasInvoiceAddress())->toBeTrue()
        ->and($cart->shipping_address_id)->toBe($shipping->id)
        ->and($cart->invoice_address_id)->toBe($invoice->id);
});

it('adopts the users default addresses when connecting them', function (): void {
    $shippingType = AddressType::create(['type' => AddressType::SHIPPING, 'name' => 'Shipping']);
    $invoiceType = AddressType::create(['type' => AddressType::INVOICE, 'name' => 'Invoice']);

    $user = User::factory()->create();
    $shipping = $user->addresses()->create(['address_type_id' => $shippingType->id, 'default' => true, 'city' => 'Delft']);
    $invoice = $user->addresses()->create(['address_type_id' => $invoiceType->id, 'default' => true, 'city' => 'Leiden']);

    $cart = ShoppingCart::completelyNew();
    $cart->connectUser($user);
    $cart = $cart->fresh();

    expect($cart->user_id)->toBe($user->id)
        ->and($cart->shipping_address_id)->toBe($shipping->id)
        ->and($cart->invoice_address_id)->toBe($invoice->id);
});

it('adopts the customer a user is linked to', function (): void {
    $customer = Customer::factory()->create();
    $user = User::factory()->create(['customer_id' => $customer->id]);
    $cart = ShoppingCart::completelyNew();

    $cart->connectUser($user);

    expect($cart->fresh()->customer_id)->toBe($customer->id);
});

it('disconnects a user from the cart', function (): void {
    $user = User::factory()->create();
    $cart = ShoppingCart::completelyNew();
    $cart->connectUser($user);

    $cart->disconnectUser();

    expect($cart->fresh()->user_id)->toBeNull();
});

it('resolves the purchasable behind a line', function (): void {
    $cart = ShoppingCart::completelyNew();
    $item = $cart->add(Product::factory()->create(), 1);

    expect($item->resolvePurchasable())->toBeInstanceOf(Product::class);
});

it('reports a shipping address is absent by default', function (): void {
    $cart = ShoppingCart::completelyNew();

    expect($cart->hasShippingAddress())->toBeFalse()
        ->and($cart->hasInvoiceAddress())->toBeFalse();
});
