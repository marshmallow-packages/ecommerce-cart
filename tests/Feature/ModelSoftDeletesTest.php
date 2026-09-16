<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Marshmallow\Ecommerce\Cart\Models\Customer;
use Marshmallow\Ecommerce\Cart\Models\Discount;
use Marshmallow\Ecommerce\Cart\Models\Order;
use Marshmallow\Ecommerce\Cart\Models\Prospect;
use Marshmallow\Ecommerce\Cart\Models\ShippingMethod;
use Marshmallow\Ecommerce\Cart\Models\ShoppingCart;
use Marshmallow\Ecommerce\Cart\Models\ShoppingCartItem;

/*
 * Every table that carries a deleted_at column is soft-deleting, so a delete
 * through any model keeps the row for the audit trail.
 */
it('soft deletes every model whose table has a deleted_at column', function (Closure $make): void {
    /** @var Model $model */
    $model = $make();
    $class = $model::class;

    $model->delete();

    expect($class::find($model->getKey()))->toBeNull()
        ->and($class::withTrashed()->find($model->getKey()))->not->toBeNull()
        ->and($class::withTrashed()->find($model->getKey())->trashed())->toBeTrue();
})->with([
    'customer' => fn () => Customer::factory()->create(),
    'prospect' => fn () => Prospect::factory()->create(),
    'discount' => fn () => Discount::factory()->create(),
    'shipping method' => fn () => ShippingMethod::factory()->create(),
    'shopping cart' => fn () => ShoppingCart::completelyNew(),
    'shopping cart item' => fn () => ShoppingCart::completelyNew()->add(productPriced(1000), 1),
    'order' => fn () => checkoutReadyCart()->convertToOrder(),
    'order item' => fn () => checkoutReadyCart()->convertToOrder()->items->first(),
]);

it('leaves a deleted line out of the cart while keeping its row', function (): void {
    $cart = ShoppingCart::completelyNew();
    $kept = $cart->add(productPriced(1000), 1);
    $removed = $cart->add(productPriced(5000), 1);

    $removed->delete();

    $cart = $cart->fresh();
    expect($cart->items)->toHaveCount(1)
        ->and($cart->getSubtotal())->toBe(1000)
        ->and(ShoppingCartItem::withTrashed()->find($removed->id)->trashed())->toBeTrue()
        ->and($kept->fresh())->not->toBeNull();
});

it('does not resurrect a deleted line when the same product is added again', function (): void {
    $product = productPriced(1000);
    $cart = ShoppingCart::completelyNew();
    $first = $cart->add($product, 3);
    $first->delete();

    $second = $cart->fresh()->add($product, 1);

    expect($second->id)->not->toBe($first->id)
        ->and($second->quantity)->toBe(1)
        ->and($cart->fresh()->productItems())->toHaveCount(1);
});

it('hides a soft-deleted discount and shipping method from the cart', function (): void {
    $discount = Discount::factory()->create(['discount_code' => 'WEG']);
    $method = ShippingMethod::factory()->create();
    $discount->delete();
    $method->delete();

    expect(Discount::byCode('WEG'))->toBeNull()
        ->and(ShippingMethod::calculateFromCart(cartOf([[1000, 1, 21.0]])))->toBeNull();
});

it('keeps a soft-deleted order out of the customer history but still blocks a second conversion', function (): void {
    $cart = checkoutReadyCart();
    $order = $cart->convertToOrder();
    $order->delete();

    expect($order->customer->orders)->toHaveCount(0)
        ->and($cart->fresh()->convertToOrder()->id)->toBe($order->id)
        ->and(Order::withTrashed()->count())->toBe(1);
});
