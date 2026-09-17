<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Event;
use Marshmallow\Ecommerce\Cart\Events\FeeChanged;
use Marshmallow\Ecommerce\Cart\Events\ItemRemoved;
use Marshmallow\Ecommerce\Cart\Events\ShippingCalculated;
use Marshmallow\Ecommerce\Cart\Events\ShippingMethodSelected;
use Marshmallow\Ecommerce\Cart\Exceptions\PurchasableUnavailableException;
use Marshmallow\Ecommerce\Cart\Models\Customer;
use Marshmallow\Ecommerce\Cart\Models\Discount;
use Marshmallow\Ecommerce\Cart\Models\ShippingMethod;
use Marshmallow\Ecommerce\Cart\Models\ShoppingCart;
use Marshmallow\Ecommerce\Cart\Support\Price;

/*
|--------------------------------------------------------------------------
| Cart-level line operations
|--------------------------------------------------------------------------
*/

it('removes a line by model or id', function (): void {
    Event::fake([ItemRemoved::class]);
    $cart = ShoppingCart::completelyNew();
    $a = $cart->add(productPriced(1000), 1);
    $b = $cart->add(productPriced(2000), 1);

    $cart->fresh()->remove($a);
    $cart->fresh()->remove($b->id);

    expect($cart->fresh()->productItems())->toHaveCount(0);
    Event::assertDispatchedTimes(ItemRemoved::class, 2);
});

it('refuses to remove a line of another cart', function (): void {
    $other = ShoppingCart::completelyNew()->add(productPriced(1000), 1);
    $cart = ShoppingCart::completelyNew();

    expect(fn () => $cart->remove($other))->toThrow(ModelNotFoundException::class)
        ->and(fn () => $cart->remove($other->id))->toThrow(ModelNotFoundException::class)
        ->and($other->fresh())->not->toBeNull();
});

it('sets a line quantity from the cart, removing it at zero', function (): void {
    $cart = ShoppingCart::completelyNew();
    $line = $cart->add(productPriced(1000), 1);

    $cart->fresh()->setQuantity($line->id, 4);
    expect($line->fresh()->quantity)->toBe(4);

    $cart->fresh()->setQuantity($line, 0);
    expect($cart->fresh()->productItems())->toHaveCount(0);
});

it('clears every product and fee line, taking shipping and discounts along', function (): void {
    ShippingMethod::factory()->create();
    $cart = cartOf([[10000, 1, 21.0], [5000, 2, 9.0]]);
    $cart->applyDiscount(Discount::factory()->percentage(10)->create());
    $cart->fresh()->setFee('Toeslag', Price::fromGross(100, 21));
    Event::fake([ShippingCalculated::class]);

    $cart->fresh()->clear();

    $cart = $cart->fresh();
    expect($cart->items)->toHaveCount(0)
        ->and($cart->getTotalAmount())->toBe(0)
        ->and($cart->shipping_method_id)->toBeNull();
    Event::assertDispatchedTimes(ShippingCalculated::class, 1);
});

it('lists the applied discounts as models in order of application', function (): void {
    $cart = cartOf([[10000, 1, 21.0]]);
    $one = Discount::factory()->create(['discount_code' => 'EEN', 'fixed_amount' => 100, 'is_combinable' => true]);
    $two = Discount::factory()->create(['discount_code' => 'TWEE', 'fixed_amount' => 100, 'is_combinable' => true]);
    $cart->applyDiscount($one);
    $cart->fresh()->applyDiscount($two);

    expect($cart->fresh()->discounts()->pluck('discount_code')->all())->toBe(['EEN', 'TWEE'])
        ->and(ShoppingCart::completelyNew()->discounts())->toHaveCount(0);
});

/*
|--------------------------------------------------------------------------
| Stock
|--------------------------------------------------------------------------
*/

it('checks the combined quantity when a product is added again', function (): void {
    $product = productPriced(1000, 21.0, ['stock' => 6]);
    $cart = ShoppingCart::completelyNew();
    $cart->add($product, 5);

    expect(fn () => $cart->fresh()->add($product, 2))->toThrow(PurchasableUnavailableException::class)
        ->and($cart->fresh()->add($product, 1)->quantity)->toBe(6);
});

it('checks availability when a line grows and not when it shrinks', function (): void {
    $product = productPriced(1000, 21.0, ['stock' => 5]);
    $cart = ShoppingCart::completelyNew();
    $line = $cart->add($product, 5);
    $product->update(['stock' => 2]);

    expect(fn () => $line->fresh()->increaseQuantity())->toThrow(PurchasableUnavailableException::class)
        ->and($line->fresh()->decreaseQuantity(2)->quantity)->toBe(3);
});

it('checks the combined quantity when merging carts and leaves an oversized line behind', function (): void {
    $product = productPriced(1000, 21.0, ['stock' => 5]);
    $target = ShoppingCart::completelyNew();
    $target->add($product, 3);
    $source = ShoppingCart::completelyNew();
    $source->add($product, 3);

    $target->fresh()->mergeFrom($source->fresh());

    expect($target->fresh()->productItems()->sole()->quantity)->toBe(3);
});

/*
|--------------------------------------------------------------------------
| Derived lines & events
|--------------------------------------------------------------------------
*/

it('drops shipping when the last product leaves the cart', function (): void {
    ShippingMethod::factory()->create(['price_including_vat' => 495]);
    $cart = ShoppingCart::completelyNew();
    $line = $cart->add(productPriced(1000), 1);
    expect($cart->fresh()->getShippingAmount())->toBe(495);

    $line->delete();

    expect($cart->fresh()->getShippingAmount())->toBe(0)
        ->and($cart->fresh()->getTotalAmount())->toBe(0)
        ->and($cart->fresh()->shipping_method_id)->toBeNull();
});

it('announces a selected shipping method and a changed fee', function (): void {
    Event::fake([ShippingMethodSelected::class, FeeChanged::class]);
    $method = ShippingMethod::factory()->create(['price_including_vat' => 495]);
    $cart = cartOf([[1000, 1, 21.0]]);

    $cart->selectShippingMethod($method);
    $cart->fresh()->selectShippingMethod(null);
    $cart->fresh()->setFee('Toeslag', Price::fromGross(150, 21));
    $cart->fresh()->setFee('Toeslag', null);

    Event::assertDispatched(ShippingMethodSelected::class, fn (ShippingMethodSelected $e): bool => $e->method?->is($method) === true && $e->cost->amountIncludingVat === 495);
    Event::assertDispatched(ShippingMethodSelected::class, fn (ShippingMethodSelected $e): bool => $e->method === null && $e->cost->isZero());
    Event::assertDispatched(FeeChanged::class, fn (FeeChanged $e): bool => $e->description === 'Toeslag' && $e->fee?->amountIncludingVat === 150);
    Event::assertDispatched(FeeChanged::class, fn (FeeChanged $e): bool => $e->fee === null);
});

it('recalculates once for a merge, a price refresh and a reorder', function (): void {
    ShippingMethod::factory()->create();
    $a = productPriced(1000);
    $b = productPriced(2000);
    $c = productPriced(3000);

    $source = ShoppingCart::completelyNew();
    $source->add($a, 1);
    $source->add($b, 1);
    $source->add($c, 1);
    $target = ShoppingCart::completelyNew();

    Event::fake([ShippingCalculated::class]);
    $target->fresh()->mergeFrom($source->fresh());
    Event::assertDispatchedTimes(ShippingCalculated::class, 1);

    Event::fake([ShippingCalculated::class]);
    $a->update(['price_cents' => 1100]);
    $b->update(['price_cents' => 2100]);
    $target->fresh()->refreshPrices();
    Event::assertDispatchedTimes(ShippingCalculated::class, 1);

    $target->prospect->update(['email' => 'once@example.com']);
    $order = $target->fresh()->convertToOrder();
    session()->forget(ShoppingCart::SESSION_KEY);
    Event::fake([ShippingCalculated::class]);
    $order->toNewCart();
    Event::assertDispatchedTimes(ShippingCalculated::class, 1);
});

it('does not recalculate at all when nothing changed inside the batch', function (): void {
    ShippingMethod::factory()->create();
    $cart = cartOf([[1000, 1, 21.0]]);
    Event::fake([ShippingCalculated::class]);

    $result = $cart->withoutRecalculating(fn (): string => 'done');

    expect($result)->toBe('done');
    Event::assertNotDispatched(ShippingCalculated::class);
});

it('persists an unsaved cart in place on its first mutation', function (): void {
    $cart = new ShoppingCart;

    $line = $cart->add(productPriced(1000), 1);

    expect($cart->exists)->toBeTrue()
        ->and($line->shopping_cart_id)->toBe($cart->id)
        ->and(session()->get(ShoppingCart::SESSION_KEY))->toBe($cart->id)
        ->and($cart->authorized())->toBeTrue()
        ->and($cart->fresh()->getSubtotal())->toBe(1000);
});

/*
|--------------------------------------------------------------------------
| Customer relations
|--------------------------------------------------------------------------
*/

it('exposes all carts of a customer and the latest one', function (): void {
    $customer = Customer::factory()->create();
    $older = ShoppingCart::completelyNew();
    $older->update(['customer_id' => $customer->id]);
    $this->travel(1)->minutes();
    $newer = ShoppingCart::completelyNew();
    $newer->update(['customer_id' => $customer->id]);

    expect($customer->carts)->toHaveCount(2)
        ->and($customer->cart->is($newer))->toBeTrue();
});
