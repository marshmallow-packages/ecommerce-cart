<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Marshmallow\Ecommerce\Cart\Contracts\Purchasable;
use Marshmallow\Ecommerce\Cart\Enums\CartItemType;
use Marshmallow\Ecommerce\Cart\Events\ItemRemoved;
use Marshmallow\Ecommerce\Cart\Exceptions\PurchasableUnavailableException;
use Marshmallow\Ecommerce\Cart\Models\ShoppingCart;
use Marshmallow\Ecommerce\Cart\Models\ShoppingCartItem;
use Marshmallow\Ecommerce\Cart\Support\Price;
use Workbench\App\Models\Product;

/*
|--------------------------------------------------------------------------
| Quantity
|--------------------------------------------------------------------------
*/

it('clamps a quantity of zero or less to one', function (int $requested): void {
    $item = ShoppingCart::completelyNew()->add(productPriced(1000), 3);

    $item->setQuantity($requested);

    expect($item->fresh()->quantity)->toBe(1);
})->with([0, -1, -100]);

it('steps the quantity by one by default', function (): void {
    $item = ShoppingCart::completelyNew()->add(productPriced(1000), 2);

    $item->increaseQuantity();
    expect($item->fresh()->quantity)->toBe(3);

    $item->fresh()->decreaseQuantity();
    expect($item->fresh()->quantity)->toBe(2);
});

it('reprices a combined line when the merged quantity reaches a tier', function (): void {
    $product = Product::factory()->tiered(['10' => 450])->create(['price_cents' => 475]);
    $cart = ShoppingCart::completelyNew();

    $cart->add($product, 5);
    $line = $cart->fresh()->add($product, 5);

    expect($line->fresh()->quantity)->toBe(10)
        ->and($line->fresh()->price_including_vat)->toBe(450)
        ->and($cart->fresh()->getSubtotal())->toBe(4500);
});

it('reprices when the tier price itself changes and the line is refreshed', function (): void {
    $product = Product::factory()->tiered(['10' => 450])->create(['price_cents' => 475]);
    $cart = ShoppingCart::completelyNew();
    $line = $cart->add($product, 10);

    $product->update(['price_tiers' => ['10' => 400]]);
    $changed = $cart->fresh()->refreshPrices();

    expect($changed)->toHaveCount(1)
        ->and($line->fresh()->price_including_vat)->toBe(400);
});

it('reprices a line whose vat rate moved even when the gross stayed the same', function (): void {
    $product = productPriced(1000, 21.0);
    $cart = ShoppingCart::completelyNew();
    $line = $cart->add($product, 1);

    $product->update(['vat_percentage' => 9.0]);
    $cart->fresh()->refreshPrices();

    expect((float) $line->fresh()->vat_percentage)->toBe(9.0)
        ->and($line->fresh()->price_excluding_vat)->toBe(917);
});

it('refreshes only the lines that moved and reports exactly those', function (): void {
    $moved = productPriced(1000);
    $still = productPriced(2000);
    $cart = ShoppingCart::completelyNew();
    $cart->add($moved, 1);
    $cart->add($still, 1);
    $moved->update(['price_cents' => 1500]);

    $changed = $cart->fresh()->refreshPrices();

    expect($changed)->toHaveCount(1)
        ->and((string) $changed->first()->purchasable_id)->toBe((string) $moved->id)
        ->and($cart->fresh()->getSubtotal())->toBe(3500);
});

/*
|--------------------------------------------------------------------------
| Signature & snapshot
|--------------------------------------------------------------------------
*/

it('signs a line by purchasable, type and meta', function (): void {
    $product = productPriced(1000);
    $cart = ShoppingCart::completelyNew();

    $plain = $cart->add($product, 1);
    $withMeta = $cart->add($product, 1, ['gift' => true]);
    $asFee = $cart->addCustom('Zelfde product als fee', Price::fromGross(1000, 21), CartItemType::Fee, purchasable: $product);

    expect($plain->signature)->toBe($cart->fresh()->add($product, 1)->signature)
        ->and($plain->signature)->not->toBe($withMeta->signature)
        ->and($plain->signature)->not->toBe($asFee->signature)
        ->and($plain->signature)->toHaveLength(32);
});

it('keeps combining the same product after its line was updated', function (): void {
    $product = productPriced(1000);
    $cart = ShoppingCart::completelyNew();
    $line = $cart->add($product, 1);

    $line->setQuantity(2);
    $cart->fresh()->add($product, 1);

    expect($cart->fresh()->productItems())->toHaveCount(1)
        ->and($line->fresh()->quantity)->toBe(3)
        ->and($line->fresh()->signature)->toBe($line->fresh()->buildSignature());
});

it('re-signs a line when its meta changes', function (): void {
    $item = ShoppingCart::completelyNew()->add(productPriced(1000), 1, ['size' => 'M']);
    $before = $item->signature;

    $item->update(['meta' => ['size' => 'L']]);

    expect($item->fresh()->signature)->not->toBe($before)
        ->and($item->fresh()->signature)->toBe($item->fresh()->buildSignature());
});

it('rewrites every snapshot column when a new price is applied', function (): void {
    $item = ShoppingCart::completelyNew()->add(productPriced(1000, 21.0), 2);

    $item->applyPrice(Price::fromGross(1090, 9.0, 'USD'));
    $item = $item->fresh();

    expect($item->price_including_vat)->toBe(1090)
        ->and($item->price_excluding_vat)->toBe(1000)
        ->and($item->vat_amount)->toBe(90)
        ->and((float) $item->vat_percentage)->toBe(9.0)
        ->and($item->currency)->toBe('USD')
        ->and($item->getTotalAmount())->toBe(2180);
});

it('casts its columns to the documented types', function (): void {
    $item = ShoppingCart::completelyNew()->add(productPriced(1000, 21.0), 2, ['size' => 'M'])->fresh();

    expect($item->type)->toBe(CartItemType::Product)
        ->and($item->quantity)->toBeInt()
        ->and($item->price_including_vat)->toBeInt()
        ->and($item->price_excluding_vat)->toBeInt()
        ->and($item->vat_amount)->toBeInt()
        ->and($item->vat_percentage)->toBeFloat()
        ->and($item->meta)->toBe(['size' => 'M'])
        ->and($item->visible_in_cart)->toBeTrue()
        ->and($item->custom_price)->toBeFalse();
});

it('marks a custom line as caller-priced and a purchasable line as not', function (): void {
    $cart = ShoppingCart::completelyNew();

    $product = $cart->add(productPriced(1000), 1);
    $custom = $cart->addCustom('Gravure', Price::fromGross(500, 21), CartItemType::Product);
    $hidden = $cart->addCustom('Verborgen', Price::fromGross(500, 21), CartItemType::Product, visibleInCart: false, combine: false);

    expect($product->fresh()->custom_price)->toBeFalse()
        ->and($custom->fresh()->custom_price)->toBeTrue()
        ->and($custom->fresh()->purchasable_id)->toBeNull()
        ->and($hidden->fresh()->visible_in_cart)->toBeFalse()
        ->and($cart->fresh()->visibleItems())->toHaveCount(2)
        ->and($cart->fresh()->productItems())->toHaveCount(3);
});

/*
|--------------------------------------------------------------------------
| Availability hook
|--------------------------------------------------------------------------
*/

it('passes the requested quantity and the cart to the availability hook', function (): void {
    $purchasable = new class implements Purchasable
    {
        /** @var array<int, array{0: int, 1: string|null}> */
        public array $calls = [];

        public function getPurchasableKey(): int|string
        {
            return 'sku-1';
        }

        public function getPurchasableName(): string
        {
            return 'Hooked';
        }

        public function getPurchasablePrice(int $quantity = 1): Price
        {
            return Price::fromGross(100, 21.0, 'EUR');
        }

        public function isAvailableForPurchase(int $quantity, ?ShoppingCart $cart = null): bool
        {
            $this->calls[] = [$quantity, $cart?->id];

            return $quantity <= 5;
        }
    };

    $cart = ShoppingCart::completelyNew();
    $cart->add($purchasable, 4);

    expect($purchasable->calls)->toBe([[4, $cart->id]])
        ->and(fn () => $cart->add($purchasable, 6))->toThrow(PurchasableUnavailableException::class)
        ->and($cart->fresh()->productItems()->sole()->quantity)->toBe(4);
});

it('checks the requested quantity against stock', function (): void {
    $product = productPriced(1000, 21.0, ['stock' => 5]);
    $cart = ShoppingCart::completelyNew();

    expect(fn () => $cart->add($product, 6))->toThrow(PurchasableUnavailableException::class)
        ->and($cart->add($product, 5)->quantity)->toBe(5);
});

/*
|--------------------------------------------------------------------------
| Deletion & relations
|--------------------------------------------------------------------------
*/

it('does not announce the removal of a non-product line', function (): void {
    Event::fake([ItemRemoved::class]);
    $cart = cartOf([[1000, 1, 21.0]]);
    $cart->setFee('Toeslag', Price::fromGross(100, 21));

    $cart->fresh()->feeItems()->sole()->delete();

    Event::assertNotDispatched(ItemRemoved::class);
});

it('belongs to its cart', function (): void {
    $cart = ShoppingCart::completelyNew();
    $item = $cart->add(productPriced(1000), 1);

    expect($item->cart->is($cart))->toBeTrue()
        ->and(ShoppingCartItem::visible()->count())->toBe(1);
});
