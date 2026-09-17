<?php

declare(strict_types=1);

use Marshmallow\Addressable\Models\AddressType;
use Marshmallow\Ecommerce\Cart\Enums\CartItemType;
use Marshmallow\Ecommerce\Cart\Exceptions\CartLockedException;
use Marshmallow\Ecommerce\Cart\Models\Discount;
use Marshmallow\Ecommerce\Cart\Models\ShippingMethod;
use Marshmallow\Ecommerce\Cart\Models\ShoppingCart;
use Marshmallow\Ecommerce\Cart\Support\Price;

it('combines lines with the same options and keeps different options apart', function (): void {
    $product = productPriced(1000);
    $target = ShoppingCart::completelyNew();
    $target->add($product, 1, ['size' => 'M']);

    $source = ShoppingCart::completelyNew();
    $source->add($product, 2, ['size' => 'M']);
    $source->add($product, 1, ['size' => 'L']);

    $target->fresh()->mergeFrom($source->fresh());

    $lines = $target->fresh()->productItems();
    expect($lines)->toHaveCount(2)
        ->and($lines->firstWhere('meta', ['size' => 'M'])->quantity)->toBe(3)
        ->and($lines->firstWhere('meta', ['size' => 'L'])->quantity)->toBe(1);
});

it('copies a note and addresses only where the target has none', function (): void {
    $type = AddressType::create(['type' => AddressType::SHIPPING, 'name' => 'Shipping']);

    $target = ShoppingCart::completelyNew();
    $targetAddress = $target->prospect->addresses()->create(['address_type_id' => $type->id, 'city' => 'Utrecht']);
    $target->update(['note' => 'Bel aan bij de buren', 'shipping_address_id' => $targetAddress->id]);

    $source = ShoppingCart::completelyNew();
    $sourceAddress = $source->prospect->addresses()->create(['address_type_id' => $type->id, 'city' => 'Leiden']);
    $source->update(['note' => 'Andere notitie', 'shipping_address_id' => $sourceAddress->id, 'invoice_address_id' => $sourceAddress->id]);

    $target->fresh()->mergeFrom($source->fresh());
    $target = $target->fresh();

    expect($target->note)->toBe('Bel aan bij de buren')
        ->and($target->shipping_address_id)->toBe($targetAddress->id)
        ->and($target->invoice_address_id)->toBe($sourceAddress->id);
});

it('fills an empty note from the source', function (): void {
    $target = ShoppingCart::completelyNew();
    $source = ShoppingCart::completelyNew();
    $source->update(['note' => 'Cadeau, graag inpakken']);

    $target->fresh()->mergeFrom($source->fresh());

    expect($target->fresh()->note)->toBe('Cadeau, graag inpakken');
});

it('brings only product lines across, never derived or fee lines', function (): void {
    ShippingMethod::factory()->create(['price_including_vat' => 495]);
    $source = ShoppingCart::completelyNew();
    $source->add(productPriced(10000), 1);
    $source->fresh()->applyDiscount(Discount::factory()->create(['fixed_amount' => 1000]));
    $source->fresh()->setFee('Toeslag', Price::fromGross(150, 21));

    session()->forget(ShoppingCart::SESSION_KEY);
    $target = ShoppingCart::completelyNew();
    $target->fresh()->mergeFrom($source->fresh());
    $target = $target->fresh();

    expect($target->productItems())->toHaveCount(1)
        ->and($target->discountItems())->toHaveCount(0)
        ->and($target->feeItems())->toHaveCount(0)
        // The target computes its own shipping from its new contents.
        ->and($target->shippingItems())->toHaveCount(1);
});

it('keeps the source price snapshot and custom price flag on a copied line', function (): void {
    $product = productPriced(1000);
    $source = ShoppingCart::completelyNew();
    $source->addCustom('Bundelprijs', Price::fromGross(400, 21, 'EUR'), CartItemType::Product, purchasable: $product, quantity: 2);

    $target = ShoppingCart::completelyNew();
    $target->fresh()->mergeFrom($source->fresh());

    $copied = $target->fresh()->productItems()->sole();
    expect($copied->price_including_vat)->toBe(400)
        ->and($copied->custom_price)->toBeTrue()
        ->and($copied->quantity)->toBe(2)
        ->and($copied->shopping_cart_id)->toBe($target->id);
});

it('absorbs a line whose product has since vanished', function (): void {
    $product = productPriced(1000);
    $source = ShoppingCart::completelyNew();
    $source->add($product, 1);
    $product->delete();

    $target = ShoppingCart::completelyNew();
    $target->fresh()->mergeFrom($source->fresh());

    expect($target->fresh()->productItems())->toHaveCount(1);
});

it('re-evaluates the target discount over the merged contents', function (): void {
    $target = ShoppingCart::completelyNew();
    $target->add(productPriced(10000), 1);
    $target->fresh()->applyDiscount(Discount::factory()->percentage(10)->create());

    $source = ShoppingCart::completelyNew();
    $source->add(productPriced(5000), 1);

    $target->fresh()->mergeFrom($source->fresh());

    expect($target->fresh()->getDiscountAmount())->toBe(-1500);
});

it('soft deletes the source cart after merging', function (): void {
    $target = ShoppingCart::completelyNew();
    $source = ShoppingCart::completelyNew();
    $source->add(productPriced(1000), 1);

    $target->fresh()->mergeFrom($source->fresh());

    expect(ShoppingCart::find($source->id))->toBeNull()
        ->and(ShoppingCart::withTrashed()->find($source->id)->trashed())->toBeTrue();
});

it('refuses to merge into a confirmed cart and leaves the source intact', function (): void {
    $target = ShoppingCart::completelyNew();
    $target->forceFill(['confirmed_at' => now()])->save();
    $source = ShoppingCart::completelyNew();
    $source->add(productPriced(1000), 1);

    expect(fn () => $target->fresh()->mergeFrom($source->fresh()))->toThrow(CartLockedException::class)
        ->and(ShoppingCart::find($source->id))->not->toBeNull()
        ->and($source->fresh()->productItems())->toHaveCount(1);
});
