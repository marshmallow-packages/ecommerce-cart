<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Marshmallow\Ecommerce\Cart\Models\ShoppingCart;
use Marshmallow\Ecommerce\Cart\Tests\TestCase;
use Workbench\App\Models\Product;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature', 'Unit', 'Arch');

// The upgrade migration rebuilds tables, which cannot happen inside the
// transaction RefreshDatabase wraps a test in. Each test there starts from a
// fresh in-memory database instead.
pest()->extend(TestCase::class)->in('Upgrade');

/*
|--------------------------------------------------------------------------
| Shared helpers
|--------------------------------------------------------------------------
|
| Small builders used across the feature suite. Every cart-returning helper
| hands back a fresh instance so the `items` relation reflects the database.
|
*/

/**
 * A purchasable at a given gross unit price.
 *
 * @param  array<string, mixed>  $attributes
 */
function productPriced(int $cents, float $vatPercentage = 21.0, array $attributes = []): Product
{
    return Product::factory()->create(array_merge([
        'price_cents' => $cents,
        'vat_percentage' => $vatPercentage,
    ], $attributes));
}

/**
 * A fresh cart holding the given product lines.
 *
 * @param  array<int, array{0: int, 1?: int, 2?: float}>  $lines  [gross cents, quantity, vat percentage]
 */
function cartOf(array $lines = []): ShoppingCart
{
    $cart = ShoppingCart::completelyNew();

    foreach ($lines as [$cents, $quantity, $vat]) {
        $cart->add(productPriced($cents, $vat), $quantity);
    }

    return $cart->fresh();
}

/**
 * A cart whose prospect has enough identity to be converted into an order.
 */
function checkoutReadyCart(int $cents = 10000, int $quantity = 1): ShoppingCart
{
    $cart = cartOf([[$cents, $quantity, 21.0]]);
    $cart->prospect?->update([
        'first_name' => 'Test',
        'last_name' => 'Buyer',
        'email' => 'buyer-'.$cart->display_id.'@example.com',
    ]);

    return $cart->fresh();
}
