<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Marshmallow\Addressable\Models\AddressType;
use Marshmallow\Ecommerce\Cart\Enums\DiscountEligibility;
use Marshmallow\Ecommerce\Cart\Events\CartConfirmed;
use Marshmallow\Ecommerce\Cart\Events\CartReopened;
use Marshmallow\Ecommerce\Cart\Exceptions\CartConvertedException;
use Marshmallow\Ecommerce\Cart\Exceptions\CartLockedException;
use Marshmallow\Ecommerce\Cart\Exceptions\DiscountException;
use Marshmallow\Ecommerce\Cart\Exceptions\EmptyCartException;
use Marshmallow\Ecommerce\Cart\Exceptions\PurchasableUnavailableException;
use Marshmallow\Ecommerce\Cart\Models\Discount;
use Marshmallow\Ecommerce\Cart\Models\ShoppingCart;
use Marshmallow\Ecommerce\Cart\Support\Price;
use Workbench\App\Models\User;

/*
|--------------------------------------------------------------------------
| confirm()
|--------------------------------------------------------------------------
*/

it('freezes the cart and announces it', function (): void {
    Event::fake([CartConfirmed::class]);
    $cart = cartOf([[1000, 1, 21.0]]);

    $cart->confirm();

    expect($cart->isConfirmed())->toBeTrue()
        ->and($cart->isOpen())->toBeFalse()
        ->and($cart->fresh()->confirmed_at)->not->toBeNull()
        ->and(fn () => $cart->fresh()->add(productPriced(100), 1))->toThrow(CartLockedException::class);
    Event::assertDispatched(CartConfirmed::class, fn (CartConfirmed $e): bool => $e->cart->is($cart));
});

it('is idempotent', function (): void {
    $cart = cartOf([[1000, 1, 21.0]]);
    $cart->confirm();
    $stamp = $cart->fresh()->confirmed_at;

    Event::fake([CartConfirmed::class]);
    $this->travel(1)->hours();
    $cart->fresh()->confirm();

    expect($cart->fresh()->confirmed_at->equalTo($stamp))->toBeTrue();
    Event::assertNotDispatched(CartConfirmed::class);
});

it('refuses to confirm a cart without products', function (): void {
    $cart = ShoppingCart::completelyNew();
    $cart->setFee('Toeslag', Price::fromGross(100, 21));

    expect(fn () => $cart->fresh()->confirm())->toThrow(EmptyCartException::class)
        ->and($cart->fresh()->isOpen())->toBeTrue();
});

it('refuses to confirm when a line is no longer available in its quantity', function (): void {
    $product = productPriced(1000, 21.0, ['stock' => 5]);
    $cart = ShoppingCart::completelyNew();
    $cart->add($product, 5);
    $product->update(['stock' => 4]);

    expect(fn () => $cart->fresh()->confirm())->toThrow(PurchasableUnavailableException::class)
        ->and($cart->fresh()->isOpen())->toBeTrue();
});

it('skips the availability check on confirm when disabled', function (): void {
    config()->set('cart.stock.check_on_checkout', false);
    $product = productPriced(1000, 21.0, ['stock' => 5]);
    $cart = ShoppingCart::completelyNew();
    $cart->add($product, 5);
    $product->update(['stock' => 0]);

    expect($cart->fresh()->confirm()->isConfirmed())->toBeTrue();
});

it('re-validates every voucher with what is known at confirmation', function (): void {
    // The code was applied before the customer identified themselves; by
    // confirmation the e-mail is known and the once-per-customer rule bites.
    $discount = Discount::factory()->create(['discount_code' => 'SOLO', 'is_once_per_customer' => true, 'fixed_amount' => 100]);
    $earlier = checkoutReadyCart();
    $earlier->prospect->update(['email' => 'repeat@example.com']);
    $earlier->fresh()->applyDiscount($discount);
    $earlier->fresh()->convertToOrder();

    session()->forget(ShoppingCart::SESSION_KEY);
    $cart = cartOf([[10000, 1, 21.0]]);
    $cart->applyDiscount($discount->fresh());
    $cart->prospect->update(['email' => 'repeat@example.com']);

    expect(fn () => $cart->fresh()->confirm())->toThrow(DiscountException::class, 'already used')
        ->and($cart->fresh()->isOpen())->toBeTrue()
        ->and($cart->fresh()->discountItems())->toHaveCount(1);
});

it('refuses a voucher the customer is not eligible for at confirmation', function (): void {
    $cart = cartOf([[10000, 1, 21.0]]);
    $cart->prospect->update(['email' => 'vip@example.com']);
    $discount = Discount::factory()->create([
        'eligible_for' => DiscountEligibility::Emails,
        'eligible_for_emails' => ['vip@example.com'],
        'fixed_amount' => 100,
    ]);
    $cart->fresh()->applyDiscount($discount);
    $cart->prospect->update(['email' => 'someone-else@example.com']);

    expect(fn () => $cart->fresh()->confirm())->toThrow(DiscountException::class);
});

/*
|--------------------------------------------------------------------------
| reopen()
|--------------------------------------------------------------------------
*/

it('reopens a confirmed cart and announces it', function (): void {
    Event::fake([CartReopened::class]);
    $cart = cartOf([[1000, 1, 21.0]]);
    $cart->confirm();

    $cart->fresh()->reopen();

    expect($cart->fresh()->isOpen())->toBeTrue()
        ->and($cart->fresh()->add(productPriced(100), 1)->quantity)->toBe(1);
    Event::assertDispatched(CartReopened::class);
});

it('treats reopening an open cart as a no-op', function (): void {
    Event::fake([CartReopened::class]);
    $cart = cartOf([[1000, 1, 21.0]]);

    $cart->reopen();

    Event::assertNotDispatched(CartReopened::class);
});

/*
|--------------------------------------------------------------------------
| Converted carts
|--------------------------------------------------------------------------
*/

it('closes a cart for good once it became an order', function (): void {
    $cart = checkoutReadyCart();
    $cart->convertToOrder();
    $cart = $cart->fresh();

    expect($cart->isConverted())->toBeTrue()
        ->and($cart->isConfirmed())->toBeTrue()
        ->and($cart->order)->not->toBeNull()
        ->and(fn () => $cart->reopen())->toThrow(CartConvertedException::class)
        ->and(fn () => $cart->confirm())->toThrow(CartConvertedException::class)
        ->and(fn () => $cart->add(productPriced(100), 1))->toThrow(CartConvertedException::class)
        ->and($cart->paymentAllowed())->toBeFalse();
});

it('replaces a converted cart in the session with a fresh one', function (): void {
    $cart = checkoutReadyCart();
    $cart->convertToOrder();

    $resolved = ShoppingCart::getBySession();

    expect($resolved->is($cart))->toBeFalse()
        ->and($resolved->isOpen())->toBeTrue();
});

it('never offers a converted cart as the user open cart', function (): void {
    $user = User::factory()->create();
    $cart = checkoutReadyCart();
    $cart->connectUser($user);
    $cart->fresh()->convertToOrder();

    expect(ShoppingCart::latestOpenForUser($user))->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Mass assignment
|--------------------------------------------------------------------------
*/

it('ignores lifecycle columns coming in through mass assignment', function (): void {
    $cart = cartOf([[1000, 1, 21.0]]);
    $token = $cart->guard_token;

    $cart->update([
        'note' => 'Hallo',
        'confirmed_at' => now(),
        'converted_at' => now(),
        'abandoned_at' => now(),
        'guard_token' => 'stolen',
        'display_id' => 999999,
    ]);

    $cart = $cart->fresh();
    expect($cart->note)->toBe('Hallo')
        ->and($cart->confirmed_at)->toBeNull()
        ->and($cart->converted_at)->toBeNull()
        ->and($cart->abandoned_at)->toBeNull()
        ->and($cart->guard_token)->toBe($token)
        ->and($cart->display_id)->not->toBe(999999);
});

it('keeps addresses and note under the lock', function (): void {
    $type = AddressType::create(['type' => 'SHIPPING', 'name' => 'Shipping']);
    $cart = cartOf([[1000, 1, 21.0]]);
    $address = $cart->prospect->addresses()->create(['address_type_id' => $type->id, 'city' => 'Utrecht']);
    $cart->confirm();

    expect(fn () => $cart->fresh()->connectShippingAddress($address))->toThrow(CartLockedException::class)
        ->and(fn () => $cart->fresh()->connectInvoiceAddress($address))->toThrow(CartLockedException::class);
});
