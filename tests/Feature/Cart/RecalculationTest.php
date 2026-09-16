<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Marshmallow\Ecommerce\Cart\Enums\DiscountAppliesTo;
use Marshmallow\Ecommerce\Cart\Events\ShippingCalculated;
use Marshmallow\Ecommerce\Cart\Models\Discount;
use Marshmallow\Ecommerce\Cart\Models\ShippingMethod;
use Marshmallow\Ecommerce\Cart\Models\ShoppingCart;
use Marshmallow\Ecommerce\Cart\Support\Price;

/*
|--------------------------------------------------------------------------
| Shipping follows the contents
|--------------------------------------------------------------------------
*/

it('makes shipping free once a quantity change crosses the threshold, and paid again below it', function (): void {
    ShippingMethod::factory()->create(['price_including_vat' => 495, 'free_from_amount' => 5000]);
    $cart = ShoppingCart::completelyNew();
    $item = $cart->add(productPriced(2000), 1);

    expect($cart->fresh()->getShippingAmount())->toBe(495);

    $item->setQuantity(3);
    expect($cart->fresh()->getShippingAmount())->toBe(0);

    $item->fresh()->setQuantity(2);
    expect($cart->fresh()->getShippingAmount())->toBe(495);
});

it('switches to the method whose band the new subtotal falls in', function (): void {
    $paid = ShippingMethod::factory()->create(['name' => 'Paid', 'price_including_vat' => 495, 'sort' => 1]);
    $paid->conditions()->create(['minimum_amount_including_vat' => 0, 'maximum_amount_including_vat' => 9999]);
    $free = ShippingMethod::factory()->create(['name' => 'Free', 'price_including_vat' => 0, 'sort' => 2]);
    $free->conditions()->create(['minimum_amount_including_vat' => 10000]);

    $cart = ShoppingCart::completelyNew();
    $item = $cart->add(productPriced(6000), 1);
    expect($cart->fresh()->getShippingItem()->description)->toBe('Paid');

    $item->setQuantity(2);
    expect($cart->fresh()->getShippingItem()->description)->toBe('Free')
        ->and($cart->fresh()->shipping_method_id)->toBe($free->id);
});

it('clears shipping when no method matches the cart any more', function (): void {
    Event::fake([ShippingCalculated::class]);
    $method = ShippingMethod::factory()->create(['price_including_vat' => 495]);
    $method->conditions()->create(['minimum_amount_including_vat' => 1000]);

    $cart = ShoppingCart::completelyNew();
    $item = $cart->add(productPriced(5000), 1);
    expect($cart->fresh()->shipping_method_id)->toBe($method->id);

    $item->delete();

    $cart = $cart->fresh();
    expect($cart->shipping_method_id)->toBeNull()
        ->and($cart->getShippingItem())->toBeNull()
        ->and($cart->getTotalAmount())->toBe(0);
    Event::assertDispatched(ShippingCalculated::class, fn (ShippingCalculated $e): bool => $e->method === null && $e->cost->isZero());
});

it('announces the method and cost it settled on', function (): void {
    Event::fake([ShippingCalculated::class]);
    $method = ShippingMethod::factory()->create(['name' => 'PostNL', 'price_including_vat' => 495]);

    $cart = ShoppingCart::completelyNew();
    $cart->add(productPriced(1000), 1);

    Event::assertDispatched(ShippingCalculated::class, fn (ShippingCalculated $e): bool => $e->cart->is($cart)
        && $e->method?->is($method)
        && $e->cost->amountIncludingVat === 495);
});

it('never stacks derived lines when the contents change repeatedly', function (): void {
    ShippingMethod::factory()->create(['price_including_vat' => 495]);
    $cart = ShoppingCart::completelyNew();
    $item = $cart->add(productPriced(10000), 1);
    $cart->fresh()->applyDiscount(Discount::factory()->percentage(10)->create());

    $item->setQuantity(2);
    $item->fresh()->setQuantity(3);
    $cart->fresh()->add(productPriced(500), 1);

    $cart = $cart->fresh();
    expect($cart->shippingItems())->toHaveCount(1)
        ->and($cart->discountItems())->toHaveCount(1)
        ->and($cart->getDiscountAmount())->toBe(-3050);
});

/*
|--------------------------------------------------------------------------
| The customer's shipping choice
|--------------------------------------------------------------------------
*/

it('keeps the shipping method the customer picked when the contents change', function (): void {
    ShippingMethod::factory()->create(['name' => 'Cheap', 'price_including_vat' => 295, 'sort' => 1]);
    $express = ShippingMethod::factory()->create(['name' => 'Express', 'price_including_vat' => 995, 'sort' => 2]);
    $cart = cartOf([[1000, 1, 21.0]]);
    $cart->selectShippingMethod($express);

    $cart->fresh()->add(productPriced(1000), 1);

    $cart = $cart->fresh();
    expect($cart->shipping_method_id)->toBe($express->id)
        ->and($cart->getShippingItem()->description)->toBe('Express')
        ->and($cart->getShippingAmount())->toBe(995);
});

it('re-prices the picked method against the new subtotal', function (): void {
    ShippingMethod::factory()->create(['name' => 'Cheap', 'price_including_vat' => 295, 'sort' => 1]);
    $express = ShippingMethod::factory()->create(['name' => 'Express', 'price_including_vat' => 995, 'sort' => 2, 'free_from_amount' => 5000]);
    $cart = cartOf([[3000, 1, 21.0]]);
    $cart->selectShippingMethod($express);
    expect($cart->fresh()->getShippingAmount())->toBe(995);

    $cart->fresh()->add(productPriced(3000), 1);

    expect($cart->fresh()->getShippingItem()->description)->toBe('Express')
        ->and($cart->fresh()->getShippingAmount())->toBe(0);
});

it('falls back to the default method once the picked one no longer applies', function (): void {
    $cheap = ShippingMethod::factory()->create(['name' => 'Cheap', 'price_including_vat' => 295, 'sort' => 1]);
    $express = ShippingMethod::factory()->create(['name' => 'Express', 'price_including_vat' => 995, 'sort' => 2]);
    $express->conditions()->create(['minimum_amount_including_vat' => 0, 'maximum_amount_including_vat' => 1500]);
    $cart = cartOf([[1000, 1, 21.0]]);
    $cart->selectShippingMethod($express);

    $cart->fresh()->add(productPriced(1000), 1); // subtotal 2000: Express's band ends at 1500

    $cart = $cart->fresh();
    expect($cart->shipping_method_id)->toBe($cheap->id)
        ->and($cart->getShippingAmount())->toBe(295);
});

it('drops a picked method that has expired in the meantime', function (): void {
    $cheap = ShippingMethod::factory()->create(['name' => 'Cheap', 'price_including_vat' => 295, 'sort' => 1]);
    $express = ShippingMethod::factory()->create(['name' => 'Express', 'price_including_vat' => 995, 'sort' => 2, 'valid_till' => now()->addHour()]);
    $cart = cartOf([[1000, 1, 21.0]]);
    $cart->selectShippingMethod($express);

    $this->travel(2)->hours();
    $cart->fresh()->add(productPriced(1000), 1);

    expect($cart->fresh()->shipping_method_id)->toBe($cheap->id);
});

/*
|--------------------------------------------------------------------------
| Discounts follow the contents
|--------------------------------------------------------------------------
*/

it('drops a product-scoped discount when its only eligible line is removed', function (): void {
    $eligible = productPriced(5000);
    $cart = ShoppingCart::completelyNew();
    $line = $cart->add($eligible, 1);
    $cart->add(productPriced(5000), 1);
    $cart->fresh()->applyDiscount(Discount::factory()->create([
        'applies_to' => DiscountAppliesTo::Products,
        'applies_to_products' => [$eligible->id],
        'fixed_amount' => 500,
    ]));
    expect($cart->fresh()->getDiscountAmount())->toBe(-500);

    $line->delete();

    expect($cart->fresh()->discountItems())->toHaveCount(0)
        ->and($cart->fresh()->getTotalAmount())->toBe(5000);
});

it('re-evaluates a percentage discount after prices are refreshed', function (): void {
    $product = productPriced(10000);
    $cart = ShoppingCart::completelyNew();
    $cart->add($product, 1);
    $cart->fresh()->applyDiscount(Discount::factory()->percentage(10)->create());

    $product->update(['price_cents' => 20000]);
    $cart->fresh()->refreshPrices();

    expect($cart->fresh()->getDiscountAmount())->toBe(-2000)
        ->and($cart->fresh()->getTotalAmount())->toBe(18000);
});

it('keeps a free shipping discount equal to the shipping the customer selects', function (): void {
    ShippingMethod::factory()->create(['name' => 'Cheap', 'price_including_vat' => 295, 'sort' => 1]);
    $express = ShippingMethod::factory()->create(['name' => 'Express', 'price_including_vat' => 995, 'sort' => 2]);
    $cart = cartOf([[1000, 1, 21.0]]);
    $cart->applyDiscount(Discount::factory()->freeShipping()->create());
    expect($cart->fresh()->getDiscountAmount())->toBe(-295);

    $cart->fresh()->selectShippingMethod($express);

    $cart = $cart->fresh();
    expect($cart->getShippingAmount())->toBe(995)
        ->and($cart->getDiscountAmount())->toBe(-995)
        ->and($cart->getTotalAmount())->toBe(1000);

    $cart->selectShippingMethod(null);

    $cart = $cart->fresh();
    expect($cart->getShippingAmount())->toBe(0)
        ->and($cart->getDiscountAmount())->toBe(0)
        ->and($cart->getTotalAmount())->toBe(1000);
});

/*
|--------------------------------------------------------------------------
| Fees are independent
|--------------------------------------------------------------------------
*/

it('keeps a fee through content changes and discount recalculation', function (): void {
    $cart = cartOf([[10000, 1, 21.0]]);
    $cart->setFee('Toeslag VISA', Price::fromGross(150, 21));
    $cart->fresh()->applyDiscount(Discount::factory()->percentage(10)->create());

    $cart->fresh()->add(productPriced(10000), 1);

    $cart = $cart->fresh();
    expect($cart->feeItems())->toHaveCount(1)
        ->and($cart->getFeeAmount())->toBe(150)
        ->and($cart->getDiscountAmount())->toBe(-2000)
        ->and($cart->getTotalAmount())->toBe(18150);
});

it('leaves a percentage discount untouched by a fee', function (): void {
    $cart = cartOf([[10000, 1, 21.0]]);
    $cart->applyDiscount(Discount::factory()->percentage(10)->create());

    $cart->fresh()->setFee('Toeslag', Price::fromGross(1000, 21));

    expect($cart->fresh()->getDiscountAmount())->toBe(-1000);
});
