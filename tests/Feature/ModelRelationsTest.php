<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Event;
use Marshmallow\Ecommerce\Cart\Enums\CartItemType;
use Marshmallow\Ecommerce\Cart\Enums\DiscountAppliesTo;
use Marshmallow\Ecommerce\Cart\Events\ItemRemoved;
use Marshmallow\Ecommerce\Cart\Exceptions\DiscountException;
use Marshmallow\Ecommerce\Cart\Models\Customer;
use Marshmallow\Ecommerce\Cart\Models\Discount;
use Marshmallow\Ecommerce\Cart\Models\Order;
use Marshmallow\Ecommerce\Cart\Models\Prospect;
use Marshmallow\Ecommerce\Cart\Models\ShippingMethod;
use Marshmallow\Ecommerce\Cart\Models\ShoppingCart;
use Marshmallow\Ecommerce\Cart\Support\Price;
use Workbench\App\Models\Guest;
use Workbench\App\Models\Product;
use Workbench\App\Models\User;

it('fires ItemRemoved and recalculates when a product line is deleted', function (): void {
    Event::fake([ItemRemoved::class]);
    $cart = ShoppingCart::completelyNew();
    $item = $cart->add(Product::factory()->create(), 2);

    $item->delete();

    expect($cart->fresh()->productItems())->toHaveCount(0);
    Event::assertDispatched(ItemRemoved::class);
});

it('connects a user at creation time', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    $cart = ShoppingCart::completelyNew();

    expect($cart->user_id)->toBe($user->id);
});

it('connects a user without an address book', function (): void {
    $guest = Guest::create(['email' => 'guest@example.com']);
    $cart = ShoppingCart::completelyNew();

    $cart->connectUser($guest);

    expect($cart->fresh()->user_id)->toBe($guest->id)
        ->and($cart->fresh()->shipping_address_id)->toBeNull();
});

it('adds a non-combining custom line', function (): void {
    $cart = ShoppingCart::completelyNew();

    $cart->addCustom('Gift wrap', Price::fromGross(500, 21), CartItemType::Product, combine: false);
    $cart->addCustom('Gift wrap', Price::fromGross(500, 21), CartItemType::Product, combine: false);

    expect($cart->fresh()->productItems())->toHaveCount(2);
});

it('exposes cart relations', function (): void {
    ShippingMethod::factory()->create(['price_including_vat' => 495]);
    $cart = ShoppingCart::completelyNew();
    $cart->add(Product::factory()->create(['price_cents' => 10000]), 1);
    $cart = $cart->fresh();

    expect($cart->shippingMethod)->toBeInstanceOf(ShippingMethod::class)
        ->and($cart->prospect)->toBeInstanceOf(Prospect::class)
        ->and($cart->getRouteKeyName())->toBe('id')
        ->and($cart->shippingAddress()->getForeignKeyName())->toBe('shipping_address_id')
        ->and($cart->invoiceAddress()->getForeignKeyName())->toBe('invoice_address_id');
});

it('exposes customer relations', function (): void {
    $customer = Customer::factory()->create(['first_name' => 'Kay', 'last_name' => 'Lee']);
    $cart = ShoppingCart::completelyNew();
    $cart->update(['customer_id' => $customer->id]);

    expect($customer->getFullName())->toBe('Kay Lee')
        ->and($customer->cart)->not->toBeNull()
        ->and($customer->orders()->getRelated())->toBeInstanceOf(Order::class)
        ->and($customer->country()->getForeignKeyName())->toBe('country_id');
});

it('exposes order and order item relations', function (): void {
    $cart = ShoppingCart::completelyNew();
    $cart->prospect->update(['email' => 'buyer@example.com']);
    $cart->add(Product::factory()->create(['price_cents' => 5000]), 1);
    $order = $cart->fresh()->convertToOrder();

    expect($order->cart)->toBeInstanceOf(ShoppingCart::class)
        ->and($order->user())->toBeInstanceOf(BelongsTo::class)
        ->and($order->shippingMethod())->toBeInstanceOf(BelongsTo::class)
        ->and($order->shippingAddress()->getForeignKeyName())->toBe('shipping_address_id')
        ->and($order->invoiceAddress()->getForeignKeyName())->toBe('invoice_address_id')
        ->and($order->items->first()->order)->toBeInstanceOf(Order::class)
        ->and($order->items->first()->purchasable)->toBeInstanceOf(Product::class);
});

it('falls back to the config currency for an order with no items', function (): void {
    $cart = ShoppingCart::completelyNew();
    $cart->prospect->update(['email' => 'empty@example.com']);

    $order = $cart->fresh()->convertToOrder();

    expect($order->currency)->toBe('EUR')
        ->and($order->total_including_vat)->toBe(0);
});

it('does not re-stamp an already converted prospect', function (): void {
    $prospect = Prospect::factory()->create(['converted_at' => now()->subDay()]);
    $stampedAt = $prospect->converted_at;

    $prospect->convertToCustomer();

    expect($prospect->fresh()->converted_at->equalTo($stampedAt))->toBeTrue();
});

it('exposes the prospect country and cart relations', function (): void {
    $cart = ShoppingCart::completelyNew();
    $prospect = $cart->prospect;

    expect($prospect->country()->getForeignKeyName())->toBe('country_id')
        ->and($prospect->cart)->toBeInstanceOf(ShoppingCart::class);
});

it('scopes cart items to the visible ones', function (): void {
    ShippingMethod::factory()->create(['price_including_vat' => 495]);
    $cart = ShoppingCart::completelyNew();
    $cart->add(Product::factory()->create(['price_cents' => 10000]), 1);

    // The shipping line is invisible; the visible() scope filters it out.
    expect($cart->fresh()->items()->visible()->get())->toHaveCount(1);
});

it('exposes the shipping method condition relation', function (): void {
    $method = ShippingMethod::factory()->create(['price_including_vat' => 495]);
    $condition = $method->conditions()->create(['minimum_amount_including_vat' => 0]);

    expect($condition->shippingMethod)->toBeInstanceOf(ShippingMethod::class);
});

it('treats a line without category support as outside every category', function (): void {
    $product = Product::factory()->create(['price_cents' => 10000]);
    $cart = ShoppingCart::completelyNew();
    $cart->add($product, 1);
    $product->delete(); // resolvePurchasable() now returns null for the line

    $discount = Discount::factory()->create([
        'applies_to' => DiscountAppliesTo::Categories,
        'applies_to_product_categories' => [7],
    ]);

    expect(fn () => $cart->fresh()->applyDiscount($discount))->toThrow(DiscountException::class);
});

it('returns null resolving a purchasable that no longer exists', function (): void {
    $product = Product::factory()->create();
    $cart = ShoppingCart::completelyNew();
    $item = $cart->add($product, 1);
    $product->delete();

    expect($item->fresh()->resolvePurchasable())->toBeNull();
});
