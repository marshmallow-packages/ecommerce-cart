<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Marshmallow\Addressable\Models\AddressType;
use Marshmallow\Ecommerce\Cart\Enums\CartItemType;
use Marshmallow\Ecommerce\Cart\Enums\OrderStatus;
use Marshmallow\Ecommerce\Cart\Events\OrderCreated;
use Marshmallow\Ecommerce\Cart\Exceptions\PaymentAmountMismatchException;
use Marshmallow\Ecommerce\Cart\Models\Discount;
use Marshmallow\Ecommerce\Cart\Models\Order;
use Marshmallow\Ecommerce\Cart\Models\OrderItem;
use Marshmallow\Ecommerce\Cart\Models\ShippingMethod;
use Marshmallow\Ecommerce\Cart\Models\ShoppingCart;
use Marshmallow\Ecommerce\Cart\Support\Price;
use Workbench\App\Models\User;

/**
 * A cart that exercises every line type: 2 x 121.00 product, 4.95 shipping,
 * -10% discount and a 1.50 fee, all at 21% VAT.
 */
function fullCart(): ShoppingCart
{
    ShippingMethod::factory()->create(['name' => 'PostNL', 'price_including_vat' => 495, 'vat_percentage' => 21]);
    $cart = ShoppingCart::completelyNew();
    $cart->prospect->update(['first_name' => 'Full', 'last_name' => 'Cart', 'email' => 'full@example.com']);
    $cart->add(productPriced(12100, 21.0), 2, ['size' => 'L']);
    $cart->fresh()->applyDiscount(Discount::factory()->percentage(10)->create(['discount_code' => 'TIEN']));
    $cart->fresh()->setFee('Toeslag VISA', Price::fromGross(150, 21));
    $cart->fresh()->update(['note' => 'Graag voor 12:00']);

    return $cart->fresh();
}

it('freezes every money column of the cart onto the order', function (): void {
    $cart = fullCart();

    $order = $cart->convertToOrder(expectedTotalAmount: $cart->getTotalAmount());

    expect($order->subtotal_including_vat)->toBe(24200)
        ->and($order->subtotal_excluding_vat)->toBe(20000)
        ->and($order->subtotal_vat_amount)->toBe(4200)
        ->and($order->shipping_including_vat)->toBe(495)
        ->and($order->shipping_excluding_vat)->toBe(409)
        ->and($order->shipping_vat_amount)->toBe(86)
        ->and($order->discount_including_vat)->toBe(-2420)
        ->and($order->discount_excluding_vat)->toBe(-2000)
        ->and($order->discount_vat_amount)->toBe(-420)
        ->and($order->total_including_vat)->toBe(24200 + 495 - 2420 + 150)
        ->and($order->total_excluding_vat)->toBe(20000 + 409 - 2000 + 124)
        ->and($order->total_vat_amount)->toBe($order->total_including_vat - $order->total_excluding_vat)
        ->and($order->status)->toBe(OrderStatus::Pending)
        ->and($order->note)->toBe('Graag voor 12:00')
        ->and($order->shopping_cart_display_id)->toBe($cart->display_id);
});

it('sums its own copied lines to the same totals it stored', function (): void {
    $order = fullCart()->convertToOrder()->fresh();

    expect($order->getSubtotal())->toBe($order->subtotal_including_vat)
        ->and($order->getShippingAmount())->toBe($order->shipping_including_vat)
        ->and($order->getDiscountAmount())->toBe($order->discount_including_vat)
        ->and($order->getFeeAmount())->toBe(150)
        ->and($order->getTotalAmount())->toBe($order->total_including_vat)
        ->and($order->getTotalAmountWithoutVat())->toBe($order->total_excluding_vat)
        ->and($order->productCount())->toBe(2);
});

it('copies every line with its snapshot, meta and origin', function (): void {
    $cart = fullCart();

    $order = $cart->convertToOrder();

    expect($order->items)->toHaveCount(4)
        ->and($order->items->pluck('type')->map(fn (CartItemType $t) => $t->value)->sort()->values()->all())
        ->toBe(['DISCOUNT', 'FEE', 'PRODUCT', 'SHIPPING']);

    $product = $order->items->firstWhere('type', CartItemType::Product);
    $source = $cart->productItems()->sole();

    expect($product->shopping_cart_item_id)->toBe($source->id)
        ->and((string) $product->purchasable_id)->toBe((string) $source->purchasable_id)
        ->and($product->description)->toBe($source->description)
        ->and($product->quantity)->toBe(2)
        ->and($product->price_including_vat)->toBe(12100)
        ->and($product->price_excluding_vat)->toBe(10000)
        ->and($product->vat_amount)->toBe(2100)
        ->and((float) $product->vat_percentage)->toBe(21.0)
        ->and($product->currency)->toBe('EUR')
        ->and($product->meta)->toBe(['size' => 'L'])
        ->and($product->visible_in_cart)->toBeTrue()
        ->and($order->items->firstWhere('type', CartItemType::Shipping)->visible_in_cart)->toBeFalse()
        ->and($order->items->firstWhere('type', CartItemType::Discount)->description)->toBe('TIEN');
});

it('copies the user, addresses and shipping method onto the order', function (): void {
    $type = AddressType::create(['type' => AddressType::SHIPPING, 'name' => 'Shipping']);
    $user = User::factory()->create();
    $cart = fullCart();
    $shipping = $cart->prospect->addresses()->create(['address_type_id' => $type->id, 'city' => 'Utrecht']);
    $invoice = $cart->prospect->addresses()->create(['address_type_id' => $type->id, 'city' => 'Amsterdam']);
    $cart->update(['user_id' => $user->id, 'shipping_address_id' => $shipping->id, 'invoice_address_id' => $invoice->id]);

    $order = $cart->fresh()->convertToOrder();

    expect($order->user_id)->toBe($user->id)
        ->and($order->user->is($user))->toBeTrue()
        ->and($order->shipping_address_id)->toBe($shipping->id)
        ->and($order->invoice_address_id)->toBe($invoice->id)
        ->and($order->shipping_method_id)->toBe($cart->fresh()->shipping_method_id)
        ->and($order->shippingMethod->name)->toBe('PostNL');
});

it('falls back to the shipping address when no invoice address was given', function (): void {
    $type = AddressType::create(['type' => AddressType::SHIPPING, 'name' => 'Shipping']);
    $cart = checkoutReadyCart();
    $shipping = $cart->prospect->addresses()->create(['address_type_id' => $type->id, 'city' => 'Utrecht']);
    $cart->update(['shipping_address_id' => $shipping->id]);

    $order = $cart->fresh()->convertToOrder();

    expect($order->invoice_address_id)->toBe($shipping->id);
});

it('takes the currency from the lines', function (): void {
    $cart = ShoppingCart::completelyNew();
    $cart->prospect->update(['email' => 'usd@example.com']);
    $cart->addCustom('Dollar item', Price::fromGross(1000, 21, 'USD'), CartItemType::Product);

    expect($cart->fresh()->convertToOrder()->currency)->toBe('USD');
});

it('writes no order at all when the snapshot does not reconcile', function (): void {
    $cart = checkoutReadyCart(1000);
    $snapshot = $cart->getPayableSnapshot();
    $snapshot['total_amount'] = 999; // tampered: lines say 1000

    expect(fn () => Order::createFromSnapshot($snapshot, $cart))->toThrow(PaymentAmountMismatchException::class)
        ->and(Order::count())->toBe(0)
        ->and(OrderItem::count())->toBe(0)
        ->and($cart->fresh()->converted_at)->toBeNull();
});

it('lets a line whose product vanished through the availability check', function (): void {
    $product = productPriced(1000);
    $cart = checkoutReadyCart();
    $cart->add($product, 1);
    $product->delete();

    expect($cart->fresh()->convertToOrder()->items)->toHaveCount(2);
});

it('announces the created order', function (): void {
    Event::fake([OrderCreated::class]);
    $cart = checkoutReadyCart();

    $order = $cart->convertToOrder();

    Event::assertDispatched(OrderCreated::class, fn (OrderCreated $e): bool => $e->order->is($order));
});

it('links the customer both ways after conversion', function (): void {
    $cart = checkoutReadyCart();

    $order = $cart->convertToOrder();

    expect($cart->fresh()->customer_id)->toBe($order->customer_id)
        ->and($order->customer->orders->first()->is($order))->toBeTrue()
        ->and($order->customer->prospect_id)->toBe($cart->prospect_id)
        ->and($order->cart->is($cart))->toBeTrue();
});

it('persists status changes and exposes them through scopes', function (): void {
    $order = checkoutReadyCart()->convertToOrder();

    $order->markAsCompleted();
    expect($order->fresh()->status)->toBe(OrderStatus::Completed)
        ->and(Order::completed()->count())->toBe(1)
        ->and(Order::pending()->count())->toBe(0);

    $order->markAsRefunded();
    expect($order->fresh()->status)->toBe(OrderStatus::Refunded)
        ->and(Order::refunded()->count())->toBe(1)
        ->and(Order::completed()->count())->toBe(0)
        ->and($order->fresh()->isRefunded())->toBeTrue()
        ->and($order->fresh()->isCompleted())->toBeFalse();
});

it('changes status quietly, without firing model events', function (): void {
    $order = checkoutReadyCart()->convertToOrder();
    $fired = false;
    Order::updated(function () use (&$fired): void {
        $fired = true;
    });

    $order->markAsCanceled();

    expect($fired)->toBeFalse()
        ->and($order->fresh()->isCanceled())->toBeTrue();
});

it('casts its columns to the documented types', function (): void {
    $order = checkoutReadyCart()->convertToOrder();
    $order->update(['shipped_at' => '2026-02-01 10:00:00']);
    $order = $order->fresh();

    expect($order->status)->toBeInstanceOf(OrderStatus::class)
        ->and($order->shipped_at)->toBeInstanceOf(Carbon::class)
        ->and($order->items->first()->type)->toBeInstanceOf(CartItemType::class)
        ->and($order->items->first()->quantity)->toBeInt()
        ->and($order->items->first()->vat_percentage)->toBeFloat()
        ->and($order->items->first()->price_including_vat)->toBeInt()
        ->and($order->items->first()->visible_in_cart)->toBeBool()
        ->and($order->items->first()->meta)->toBeNull();
});
