<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Marshmallow\Ecommerce\Cart\Enums\CartItemType;
use Marshmallow\Ecommerce\Cart\Models\ShoppingCart;
use Marshmallow\Ecommerce\Cart\Models\ShoppingCartItem;
use Marshmallow\Ecommerce\Cart\Support\Price;
use Workbench\App\Models\Product;

function runCheckoutIntegrityUpgrade(): void
{
    $migration = require __DIR__.'/../../database/migrations/2026_09_17_000001_add_checkout_integrity_to_cart_tables.php';
    $migration->up();
}

it('adds the 6.1 columns and indexes', function (): void {
    expect(Schema::hasColumn('shopping_carts', 'converted_at'))->toBeTrue()
        ->and(Schema::hasColumns('shopping_cart_items', ['purchasable_type']))->toBeTrue()
        ->and(Schema::hasColumns('order_items', ['purchasable_type']))->toBeTrue()
        ->and(Schema::hasColumns('orders', ['payment_id', 'customer_snapshot', 'shipping_address_snapshot', 'invoice_address_snapshot', 'shipping_method_snapshot', 'discounts_snapshot', 'snapshot_fingerprint']))->toBeTrue()
        ->and(Schema::hasIndex('shopping_carts', 'shopping_carts_user_id_index'))->toBeTrue()
        ->and(Schema::hasIndex('shopping_cart_items', 'shopping_cart_items_shopping_cart_id_signature_index'))->toBeTrue()
        ->and(Schema::hasIndex('order_items', 'order_items_type_description_index'))->toBeTrue()
        ->and(Schema::hasIndex('customers', 'customers_email_index'))->toBeTrue();
});

it('gives 6.0 lines the configured product type and re-signs them', function (): void {
    $product = Product::factory()->create(['price_cents' => 1000]);
    $cart = ShoppingCart::completelyNew();
    $line = $cart->add($product, 1);
    // A 6.0 row: no type, signed without one.
    DB::table('shopping_cart_items')->where('id', $line->id)->update([
        'purchasable_type' => null,
        'signature' => ShoppingCartItem::signatureFor(null, $product->id, CartItemType::Product, null),
    ]);

    runCheckoutIntegrityUpgrade();

    $row = DB::table('shopping_cart_items')->where('id', $line->id)->first();
    expect($row->purchasable_type)->toBe(Product::class)
        ->and($row->signature)->toBe(ShoppingCartItem::signatureFor(Product::class, $product->id, CartItemType::Product, null))
        ->and($cart->fresh()->add($product, 1)->quantity)->toBe(2);
});

it('leaves custom lines without a purchasable untouched', function (): void {
    $cart = ShoppingCart::completelyNew();
    $line = $cart->addCustom('Gravure', Price::fromGross(500, 21), CartItemType::Product);

    runCheckoutIntegrityUpgrade();

    expect(DB::table('shopping_cart_items')->where('id', $line->id)->value('purchasable_type'))->toBeNull();
});

it('is safe to run again', function (): void {
    runCheckoutIntegrityUpgrade();
    runCheckoutIntegrityUpgrade();

    expect(Schema::hasColumn('orders', 'snapshot_fingerprint'))->toBeTrue();
});
