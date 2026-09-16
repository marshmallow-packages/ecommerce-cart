<?php

declare(strict_types=1);

use Marshmallow\Ecommerce\Cart\Enums\CartItemType;
use Marshmallow\Ecommerce\Cart\Models\ShippingMethod;
use Marshmallow\Ecommerce\Cart\Models\ShoppingCart;
use Marshmallow\Ecommerce\Cart\Support\Price;
use Workbench\App\Models\Product;

function cartWithProduct(int $priceCents = 10000, int $quantity = 1): ShoppingCart
{
    $cart = ShoppingCart::completelyNew();
    $cart->add(Product::factory()->create(['price_cents' => $priceCents, 'vat_percentage' => 21]), $quantity);

    return $cart->fresh();
}

it('adds a fee line that counts towards the total but not the subtotal', function (): void {
    $cart = cartWithProduct(10000);

    $cart->setFee('Toeslag creditcard', Price::fromGross(150, 21));
    $cart = $cart->fresh();

    expect($cart->getFeeAmount())->toBe(150)
        ->and($cart->getSubtotal())->toBe(10000)
        ->and($cart->getTotalAmount())->toBe(10150)
        ->and($cart->feeItems()->first()->type)->toBe(CartItemType::Fee);
});

it('replaces an existing fee rather than stacking', function (): void {
    $cart = cartWithProduct();
    $cart->setFee('Toeslag', Price::fromGross(150, 21));
    $cart->fresh()->setFee('Toeslag', Price::fromGross(300, 21));

    expect($cart->fresh()->feeItems())->toHaveCount(1)
        ->and($cart->fresh()->getFeeAmount())->toBe(300);
});

it('clears the fee when given null or a zero price', function (): void {
    $cart = cartWithProduct();
    $cart->setFee('Toeslag', Price::fromGross(150, 21));

    $cart->fresh()->setFee('Toeslag', null);
    expect($cart->fresh()->feeItems())->toHaveCount(0);

    $cart->fresh()->setFee('Toeslag', Price::fromGross(150, 21));
    $cart->fresh()->setFee('Toeslag', Price::zero(21));
    expect($cart->fresh()->feeItems())->toHaveCount(0);
});

it('breaks the fee into net and vat', function (): void {
    $cart = cartWithProduct();
    $cart->setFee('Toeslag', Price::fromGross(121, 21));

    $cart = $cart->fresh();
    expect($cart->getFeeAmountWithoutVat())->toBe(100)
        ->and($cart->getFeeVatAmount())->toBe(21);
});

it('applies a customer-selected shipping method', function (): void {
    $method = ShippingMethod::factory()->create(['name' => 'PostNL', 'price_including_vat' => 495]);
    $cart = cartWithProduct();

    $cart->selectShippingMethod($method);
    $cart = $cart->fresh();

    expect($cart->shipping_method_id)->toBe($method->id)
        ->and($cart->getShippingAmount())->toBe(495)
        ->and($cart->getShippingItem()->description)->toBe('PostNL');
});

it('clears shipping when selecting none (pickup)', function (): void {
    $method = ShippingMethod::factory()->create(['price_including_vat' => 495]);
    $cart = cartWithProduct();
    $cart->selectShippingMethod($method);

    $cart->fresh()->selectShippingMethod(null);
    $cart = $cart->fresh();

    expect($cart->shipping_method_id)->toBeNull()
        ->and($cart->getShippingItem())->toBeNull();
});

it('makes a method free once the subtotal reaches its threshold', function (): void {
    $method = ShippingMethod::factory()->create([
        'price_including_vat' => 495,
        'free_from_amount' => 5000,
    ]);

    $cheap = cartWithProduct(4000);
    $cheap->selectShippingMethod($method);
    expect($cheap->fresh()->getShippingAmount())->toBe(495);

    $rich = cartWithProduct(6000);
    $rich->selectShippingMethod($method);
    expect($rich->fresh()->getShippingAmount())->toBe(0);
});

it('prices a method against the cart directly', function (): void {
    $method = ShippingMethod::factory()->create(['price_including_vat' => 495, 'free_from_amount' => 5000]);

    expect($method->priceForCart(cartWithProduct(4000))->amountIncludingVat)->toBe(495)
        ->and($method->priceForCart(cartWithProduct(9000))->amountIncludingVat)->toBe(0);
});
