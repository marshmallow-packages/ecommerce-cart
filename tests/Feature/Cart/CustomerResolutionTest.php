<?php

declare(strict_types=1);

use Marshmallow\Addressable\Models\AddressType;
use Marshmallow\Ecommerce\Cart\Models\Customer;
use Marshmallow\Ecommerce\Cart\Models\Prospect;
use Marshmallow\Ecommerce\Cart\Models\ShoppingCart;
use Workbench\App\Models\User;

it('prefers the customer over the prospect for contact details', function (): void {
    $customer = Customer::factory()->create(['first_name' => 'Cus', 'last_name' => 'Tomer', 'email' => 'customer@example.com', 'phone_number' => '111']);
    $cart = ShoppingCart::completelyNew();
    $cart->prospect->update(['first_name' => 'Pro', 'last_name' => 'Spect', 'email' => 'prospect@example.com', 'phone_number' => '222']);
    $cart->update(['customer_id' => $customer->id]);
    $cart = $cart->fresh();

    expect($cart->getCustomerOrProspect())->toBeInstanceOf(Customer::class)
        ->and($cart->getCustomer()?->is($customer))->toBeTrue()
        ->and($cart->getCustomerName())->toBe('Cus Tomer')
        ->and($cart->getCustomerEmail())->toBe('customer@example.com')
        ->and($cart->getCustomerPhonenumber())->toBe('111');
});

it('falls back to the prospect as the payable customer', function (): void {
    $cart = ShoppingCart::completelyNew();

    expect($cart->getCustomer())->toBeInstanceOf(Prospect::class)
        ->and($cart->getCustomer()->is($cart->prospect))->toBeTrue()
        ->and($cart->getCustomerEmail())->toBeNull()
        ->and($cart->getCustomerPhonenumber())->toBeNull();
});

it('returns null contact details when neither customer nor prospect exists', function (): void {
    $cart = ShoppingCart::completelyNew();
    $cart->forceFill(['prospect_id' => null, 'customer_id' => null])->saveQuietly();
    $cart = $cart->fresh();

    expect($cart->getCustomerOrProspect())->toBeNull()
        ->and($cart->getCustomerName())->toBeNull()
        ->and($cart->getCustomerEmail())->toBeNull()
        ->and($cart->getCustomerPhonenumber())->toBeNull();
});

it('builds a name from whichever parts are present', function (): void {
    $cart = ShoppingCart::completelyNew();
    $cart->prospect->update(['first_name' => 'Grace', 'last_name' => null]);

    expect($cart->fresh()->getCustomerName())->toBe('Grace')
        ->and(Customer::factory()->create(['first_name' => null, 'last_name' => 'Hopper'])->getFullName())->toBe('Hopper')
        ->and(Customer::factory()->create(['first_name' => null, 'last_name' => null])->getFullName())->toBe('');
});

it('keeps the addresses already on the cart when connecting a user', function (): void {
    $shippingType = AddressType::create(['type' => AddressType::SHIPPING, 'name' => 'Shipping']);
    $invoiceType = AddressType::create(['type' => AddressType::INVOICE, 'name' => 'Invoice']);
    $user = User::factory()->create();
    $user->addresses()->create(['address_type_id' => $shippingType->id, 'default' => true, 'city' => 'Delft']);
    $user->addresses()->create(['address_type_id' => $invoiceType->id, 'default' => true, 'city' => 'Leiden']);

    $cart = ShoppingCart::completelyNew();
    $own = $cart->prospect->addresses()->create(['address_type_id' => $shippingType->id, 'city' => 'Utrecht']);
    $cart->update(['shipping_address_id' => $own->id, 'invoice_address_id' => $own->id]);

    $cart->fresh()->connectUser($user);

    expect($cart->fresh()->shipping_address_id)->toBe($own->id)
        ->and($cart->fresh()->invoice_address_id)->toBe($own->id);
});

it('connects a user whose address book has no address types configured', function (): void {
    $user = User::factory()->create();
    $cart = ShoppingCart::completelyNew();

    $cart->connectUser($user);

    expect($cart->fresh()->user_id)->toBe($user->id)
        ->and($cart->fresh()->shipping_address_id)->toBeNull()
        ->and($cart->fresh()->invoice_address_id)->toBeNull();
});

it('leaves the customer empty for a user whose customer relation is empty', function (): void {
    $user = User::factory()->create(['customer_id' => null]);
    $cart = ShoppingCart::completelyNew();

    $cart->connectUser($user);

    expect($cart->fresh()->customer_id)->toBeNull();
});

it('leaves the customer empty when the prospect maps to none', function (): void {
    $cart = ShoppingCart::completelyNew();

    $cart->addCustomerIfExists();

    expect($cart->fresh()->customer_id)->toBeNull();
});

it('clears both user and customer on disconnect', function (): void {
    $customer = Customer::factory()->create();
    $user = User::factory()->create(['customer_id' => $customer->id]);
    $cart = ShoppingCart::completelyNew();
    $cart->connectUser($user);
    expect($cart->fresh()->customer_id)->toBe($customer->id);

    $cart->disconnectUser();

    expect($cart->fresh()->user_id)->toBeNull()
        ->and($cart->fresh()->customer_id)->toBeNull();
});

it('describes the payment in the active locale', function (): void {
    $cart = ShoppingCart::completelyNew();

    expect($cart->getPayableDescription())->toBe('Order #'.$cart->display_id);

    app()->setLocale('nl');

    expect($cart->getPayableDescription())->toBe('Bestelling #'.$cart->display_id);
});
