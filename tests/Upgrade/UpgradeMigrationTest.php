<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Marshmallow\Ecommerce\Cart\Enums\CartItemType;
use Marshmallow\Ecommerce\Cart\Models\ShoppingCartItem;

/**
 * Rebuild the tables the upgrade touches in their Nova-era (v2) shape, with
 * the priceable lookup tables the backfill reads from.
 */
function legacySchema(): void
{
    Schema::disableForeignKeyConstraints();
    foreach (['order_items', 'shopping_cart_items', 'orders', 'shopping_carts', 'discounts', 'vat_rates', 'currencies'] as $table) {
        Schema::dropIfExists($table);
    }
    Schema::enableForeignKeyConstraints();

    Schema::create('vat_rates', function (Blueprint $table): void {
        $table->id();
        $table->decimal('rate', 5, 2);
    });

    Schema::create('currencies', function (Blueprint $table): void {
        $table->id();
        $table->string('code', 3);
    });

    Schema::create('shopping_carts', function (Blueprint $table): void {
        $table->uuid('id')->primary();
        $table->unsignedBigInteger('display_id');
        $table->string('hashed_ip_address')->nullable();
        $table->timestamp('confirmed_at')->nullable();
        $table->timestamps();
    });

    Schema::create('orders', function (Blueprint $table): void {
        $table->id();
        $table->uuid('shopping_cart_id');
        $table->timestamps();
    });

    foreach (['shopping_cart_items', 'order_items'] as $name) {
        Schema::create($name, function (Blueprint $table) use ($name): void {
            $table->id();
            if ($name === 'shopping_cart_items') {
                $table->uuid('shopping_cart_id');
            } else {
                $table->unsignedBigInteger('order_id');
            }
            $table->unsignedBigInteger('product_id')->nullable();
            $table->unsignedBigInteger('vatrate_id')->nullable();
            $table->unsignedBigInteger('currency_id')->nullable();
            $table->string('display_price')->nullable();
            $table->string('description');
            $table->string('type');
            $table->unsignedInteger('quantity')->default(1);
            $table->integer('price_excluding_vat')->default(0);
            $table->integer('price_including_vat')->default(0);
            $table->integer('vat_amount')->default(0);
            $table->boolean('visible_in_cart')->default(true);
            $table->timestamps();
        });
    }

    Schema::create('discounts', function (Blueprint $table): void {
        $table->id();
        $table->string('discount_code');
        $table->timestamps();
    });
}

function runUpgrade(): void
{
    /** @var Migration $migration */
    $migration = require __DIR__.'/../../database/migrations-upgrade/2024_01_01_000000_upgrade_cart_tables_to_v3.php';
    $migration->up();
}

beforeEach(function (): void {
    legacySchema();

    DB::table('vat_rates')->insert([['id' => 1, 'rate' => 21.00], ['id' => 2, 'rate' => 9.00]]);
    DB::table('currencies')->insert([['id' => 1, 'code' => 'EUR'], ['id' => 2, 'code' => 'USD']]);
    DB::table('shopping_carts')->insert(['id' => 'cart-1', 'display_id' => 1, 'hashed_ip_address' => 'abc']);
    DB::table('shopping_cart_items')->insert([
        ['shopping_cart_id' => 'cart-1', 'product_id' => 42, 'vatrate_id' => 2, 'currency_id' => 2, 'display_price' => '€ 10,90', 'description' => 'Boek', 'type' => 'PRODUCT', 'price_including_vat' => 1090],
        ['shopping_cart_id' => 'cart-1', 'product_id' => null, 'vatrate_id' => null, 'currency_id' => null, 'display_price' => null, 'description' => 'PostNL', 'type' => 'SHIPPING', 'price_including_vat' => 495],
        ['shopping_cart_id' => 'cart-1', 'product_id' => 7, 'vatrate_id' => 999, 'currency_id' => 999, 'display_price' => null, 'description' => 'Onbekend', 'type' => 'PRODUCT', 'price_including_vat' => 100],
    ]);
    DB::table('orders')->insert(['id' => 1, 'shopping_cart_id' => 'cart-1']);
    DB::table('order_items')->insert(['order_id' => 1, 'product_id' => 42, 'vatrate_id' => 1, 'currency_id' => 1, 'description' => 'Boek', 'type' => 'PRODUCT', 'price_including_vat' => 1090]);
    DB::table('discounts')->insert(['discount_code' => 'OUD']);
});

it('adds the snapshot columns and drops the legacy ones', function (): void {
    runUpgrade();

    foreach (['shopping_cart_items', 'order_items'] as $table) {
        expect(Schema::hasColumns($table, ['vat_percentage', 'currency', 'purchasable_id', 'meta']))->toBeTrue()
            ->and(Schema::hasColumn($table, 'vatrate_id'))->toBeFalse()
            ->and(Schema::hasColumn($table, 'currency_id'))->toBeFalse()
            ->and(Schema::hasColumn($table, 'product_id'))->toBeFalse()
            ->and(Schema::hasColumn($table, 'display_price'))->toBeFalse();
    }

    expect(Schema::hasColumns('shopping_cart_items', ['signature', 'custom_price']))->toBeTrue()
        ->and(Schema::hasColumn('order_items', 'signature'))->toBeFalse()
        ->and(Schema::hasColumns('shopping_carts', ['guard_token', 'shipping_method_id', 'abandoned_at']))->toBeTrue()
        ->and(Schema::hasColumn('shopping_carts', 'hashed_ip_address'))->toBeFalse()
        ->and(Schema::hasColumn('discounts', 'is_combinable'))->toBeTrue();
});

it('backfills the snapshots from the priceable lookup tables', function (): void {
    runUpgrade();

    $book = DB::table('shopping_cart_items')->where('description', 'Boek')->first();
    $shipping = DB::table('shopping_cart_items')->where('description', 'PostNL')->first();
    $orphan = DB::table('shopping_cart_items')->where('description', 'Onbekend')->first();
    $orderLine = DB::table('order_items')->first();

    expect((float) $book->vat_percentage)->toBe(9.0)
        ->and($book->currency)->toBe('USD')
        ->and((string) $book->purchasable_id)->toBe('42')
        ->and((bool) $book->custom_price)->toBeTrue()
        ->and($book->meta)->toBeNull()
        // No foreign keys at all: fall back to the configured defaults.
        ->and((float) $shipping->vat_percentage)->toBe(21.0)
        ->and($shipping->currency)->toBe('EUR')
        ->and($shipping->purchasable_id)->toBeNull()
        // Dangling foreign keys: also the defaults.
        ->and((float) $orphan->vat_percentage)->toBe(21.0)
        ->and($orphan->currency)->toBe('EUR')
        ->and((string) $orphan->purchasable_id)->toBe('7')
        ->and((float) $orderLine->vat_percentage)->toBe(21.0)
        ->and($orderLine->currency)->toBe('EUR')
        ->and((string) $orderLine->purchasable_id)->toBe('42');
});

it('signs legacy lines exactly like new lines', function (): void {
    runUpgrade();

    $book = DB::table('shopping_cart_items')->where('description', 'Boek')->first();
    $shipping = DB::table('shopping_cart_items')->where('description', 'PostNL')->first();

    expect($book->signature)->toBe(ShoppingCartItem::signatureFor(null, '42', CartItemType::Product, null))
        ->and($book->signature)->toBe((new ShoppingCartItem(['purchasable_id' => 42, 'type' => CartItemType::Product]))->buildSignature())
        ->and($shipping->signature)->toBe(ShoppingCartItem::signatureFor(null, null, CartItemType::Shipping, null))
        ->and(DB::table('shopping_cart_items')->whereNull('signature')->count())->toBe(0);
});

it('leaves a line with an unknown legacy type unsigned rather than failing', function (): void {
    DB::table('shopping_cart_items')->insert(['shopping_cart_id' => 'cart-1', 'description' => 'Raar', 'type' => 'LEGACY_TYPE', 'price_including_vat' => 1]);

    runUpgrade();

    expect(DB::table('shopping_cart_items')->where('description', 'Raar')->value('signature'))->toBeNull()
        ->and(DB::table('shopping_cart_items')->where('description', 'Boek')->value('signature'))->not->toBeNull();
});

it('honours the configured defaults for rows without a rate or currency', function (): void {
    config()->set('cart.default_vat_percentage', 6.0);
    config()->set('cart.currency', 'GBP');

    runUpgrade();

    $shipping = DB::table('shopping_cart_items')->where('description', 'PostNL')->first();
    expect((float) $shipping->vat_percentage)->toBe(6.0)
        ->and($shipping->currency)->toBe('GBP');
});

it('keeps existing data and defaults untouched otherwise', function (): void {
    runUpgrade();

    expect(DB::table('shopping_cart_items')->count())->toBe(3)
        ->and(DB::table('shopping_carts')->value('guard_token'))->toBeNull()
        ->and((bool) DB::table('discounts')->value('is_combinable'))->toBeFalse()
        ->and(DB::table('shopping_cart_items')->where('description', 'Boek')->value('price_including_vat'))->toBe(1090);
});

it('is safe to run twice', function (): void {
    runUpgrade();

    runUpgrade();

    expect(Schema::hasColumn('shopping_cart_items', 'vat_percentage'))->toBeTrue()
        ->and(DB::table('shopping_cart_items')->where('description', 'Boek')->value('currency'))->toBe('USD')
        ->and(DB::table('shopping_cart_items')->count())->toBe(3);
});

it('skips the backfill when the lookup tables are gone but still adds the columns', function (): void {
    Schema::drop('vat_rates');
    Schema::drop('currencies');

    runUpgrade();

    $book = DB::table('shopping_cart_items')->where('description', 'Boek')->first();
    expect((float) $book->vat_percentage)->toBe(21.0)
        ->and($book->currency)->toBe('EUR')
        ->and((string) $book->purchasable_id)->toBe('42');
});
