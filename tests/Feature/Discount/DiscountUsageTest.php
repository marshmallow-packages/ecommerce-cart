<?php

declare(strict_types=1);

use Marshmallow\Ecommerce\Cart\Exceptions\DiscountException;
use Marshmallow\Ecommerce\Cart\Models\Discount;
use Marshmallow\Ecommerce\Cart\Models\ShoppingCart;
use Workbench\App\Models\User;

/**
 * Place an order that redeemed the given discount, for the given e-mail.
 */
function redeem(Discount $discount, string $email, ?User $user = null): void
{
    $cart = cartOf([[10000, 1, 21.0]]);
    $cart->prospect->update(['first_name' => 'Repeat', 'email' => $email]);
    if ($user) {
        $cart->connectUser($user);
    }
    $cart->fresh()->applyDiscount($discount);
    $cart->fresh()->convertToOrder();
    session()->forget(ShoppingCart::SESSION_KEY);
}

it('allows a discount while it is below its usage limit', function (): void {
    $discount = Discount::factory()->create(['discount_code' => 'TWICE', 'total_usage_limit' => 2, 'fixed_amount' => 100]);
    redeem($discount, 'a@example.com');

    $cart = cartOf([[10000, 1, 21.0]]);
    $cart->applyDiscount($discount);

    expect($cart->fresh()->getDiscountAmount())->toBe(-100);
});

it('counts only redemptions of the same code towards the limit', function (): void {
    $limited = Discount::factory()->create(['discount_code' => 'LIMITED', 'total_usage_limit' => 1, 'fixed_amount' => 100]);
    $other = Discount::factory()->create(['discount_code' => 'OTHER', 'fixed_amount' => 100]);
    redeem($other, 'a@example.com');

    $cart = cartOf([[10000, 1, 21.0]]);
    $cart->applyDiscount($limited);

    expect($cart->fresh()->getDiscountAmount())->toBe(-100);
});

it('does not count an applied-but-unpaid cart towards the usage limit', function (): void {
    $discount = Discount::factory()->create(['discount_code' => 'PENDING', 'total_usage_limit' => 1, 'fixed_amount' => 100]);
    $first = cartOf([[10000, 1, 21.0]]);
    $first->applyDiscount($discount);

    session()->forget(ShoppingCart::SESSION_KEY);
    $second = cartOf([[10000, 1, 21.0]]);
    $second->applyDiscount($discount->fresh());

    expect($second->fresh()->getDiscountAmount())->toBe(-100);
});

it('blocks a once-per-customer discount for a returning user account', function (): void {
    $discount = Discount::factory()->create(['discount_code' => 'SOLO', 'is_once_per_customer' => true, 'fixed_amount' => 100]);
    $user = User::factory()->create(['email' => 'user@example.com']);
    redeem($discount, 'prospect-mail@example.com', $user);

    // The new cart identifies itself only through the prospect e-mail that
    // matches the user's e-mail on the earlier order.
    $cart = cartOf([[10000, 1, 21.0]]);
    $cart->prospect->update(['email' => 'user@example.com']);

    expect(fn () => $cart->fresh()->applyDiscount($discount->fresh()))
        ->toThrow(DiscountException::class, 'already used this voucher');
});

it('skips the once-per-customer check when the cart has no e-mail yet', function (): void {
    $discount = Discount::factory()->create(['discount_code' => 'SOLO', 'is_once_per_customer' => true, 'fixed_amount' => 100]);
    redeem($discount, 'repeat@example.com');

    $cart = cartOf([[10000, 1, 21.0]]);
    $cart->applyDiscount($discount->fresh());

    expect($cart->fresh()->getDiscountAmount())->toBe(-100);
});

it('allows a once-per-customer discount for a different e-mail', function (): void {
    $discount = Discount::factory()->create(['discount_code' => 'SOLO', 'is_once_per_customer' => true, 'fixed_amount' => 100]);
    redeem($discount, 'first@example.com');

    $cart = cartOf([[10000, 1, 21.0]]);
    $cart->prospect->update(['email' => 'second@example.com']);
    $cart->fresh()->applyDiscount($discount->fresh());

    expect($cart->fresh()->getDiscountAmount())->toBe(-100);
});

it('re-checks usage limits when the cart changes, dropping a code that filled up', function (): void {
    $discount = Discount::factory()->create(['discount_code' => 'LAST', 'total_usage_limit' => 1, 'fixed_amount' => 100]);
    $cart = cartOf([[10000, 1, 21.0]]);
    $cart->applyDiscount($discount);

    // Someone else redeems the last slot while this cart is still open.
    session()->forget(ShoppingCart::SESSION_KEY);
    redeem($discount->fresh(), 'other@example.com');

    $cart->fresh()->add(productPriced(1000), 1);

    expect($cart->fresh()->discountItems())->toHaveCount(0);
});
