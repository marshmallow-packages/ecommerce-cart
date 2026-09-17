<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;
use Marshmallow\Ecommerce\Cart\Contracts\Purchasable;
use Marshmallow\Ecommerce\Cart\Enums\CartItemType;
use Marshmallow\Ecommerce\Cart\Models\ShoppingCart;
use Marshmallow\Ecommerce\Cart\Models\ShoppingCartItem;
use Marshmallow\Ecommerce\Cart\Support\Price;
use Workbench\App\Models\GiftCard;
use Workbench\App\Models\Product;

it('stores the morph type of the purchasable on the line', function (): void {
    $cart = ShoppingCart::completelyNew();

    $product = $cart->add(productPriced(1000), 1);
    $card = $cart->add(GiftCard::create(['code' => 'ABC', 'value_cents' => 2500]), 1);

    expect($product->purchasable_type)->toBe(Product::class)
        ->and($card->purchasable_type)->toBe(GiftCard::class)
        ->and($card->description)->toBe('Cadeaukaart ABC')
        ->and($card->fresh()->resolvePurchasable())->toBeInstanceOf(GiftCard::class)
        ->and($cart->fresh()->getSubtotal())->toBe(3500);
});

it('keeps lines apart when two purchasable types share a key', function (): void {
    $product = productPriced(1000);
    $card = GiftCard::create(['id' => $product->id, 'code' => 'SAME', 'value_cents' => 1000]);
    $cart = ShoppingCart::completelyNew();

    $cart->add($product, 1);
    $cart->add($card, 1);

    expect($cart->fresh()->productItems())->toHaveCount(2);
});

it('honours a morph map', function (): void {
    Relation::morphMap(['gift-card' => GiftCard::class]);

    try {
        $cart = ShoppingCart::completelyNew();
        $line = $cart->add(GiftCard::create(['code' => 'MAP', 'value_cents' => 500]), 1);

        expect($line->purchasable_type)->toBe('gift-card')
            ->and($line->fresh()->resolvePurchasable())->toBeInstanceOf(GiftCard::class);
    } finally {
        Relation::morphMap([], false);
    }
});

it('falls back to the configured product model for a line without a type', function (): void {
    $product = productPriced(1000);
    $cart = ShoppingCart::completelyNew();
    $line = $cart->add($product, 1);
    DB::table('shopping_cart_items')->where('id', $line->id)->update(['purchasable_type' => null]);

    expect($line->fresh()->purchasable_type)->toBeNull()
        ->and($line->fresh()->resolvePurchasable()?->is($product))->toBeTrue();
});

it('stores the class name for a purchasable that is not an eloquent model', function (): void {
    $purchasable = new class implements Purchasable
    {
        public function getPurchasableKey(): int|string
        {
            return 'sku-9';
        }

        public function getPurchasableName(): string
        {
            return 'Plain object';
        }

        public function getPurchasablePrice(int $quantity = 1, ?ShoppingCart $cart = null): Price
        {
            return Price::fromGross(100, 21.0, 'EUR');
        }

        public function isAvailableForPurchase(int $quantity, ?ShoppingCart $cart = null): bool
        {
            return true;
        }
    };

    $line = ShoppingCart::completelyNew()->add($purchasable, 1);

    expect($line->purchasable_type)->toBe($purchasable::class)
        ->and($line->fresh()->resolvePurchasable())->toBeNull();
});

it('carries the type onto the order and back into a reorder', function (): void {
    $card = GiftCard::create(['code' => 'XYZ', 'value_cents' => 2500]);
    $cart = checkoutReadyCart(1000);
    $cart->add($card, 2);

    $order = $cart->fresh()->convertToOrder();
    session()->forget(ShoppingCart::SESSION_KEY);
    $reorder = $order->toNewCart();

    $line = $order->items->firstWhere('purchasable_type', GiftCard::class);
    expect($line)->not->toBeNull()
        ->and($line->resolvePurchasable()->is($card))->toBeTrue()
        ->and($reorder->fresh()->productItems()->firstWhere('purchasable_type', GiftCard::class)->quantity)->toBe(2);
});

it('passes the cart to the purchasable when pricing', function (): void {
    $purchasable = new class implements Purchasable
    {
        /** @var array<int, string|null> */
        public array $carts = [];

        public function getPurchasableKey(): int|string
        {
            return 'ctx';
        }

        public function getPurchasableName(): string
        {
            return 'Contextual';
        }

        public function getPurchasablePrice(int $quantity = 1, ?ShoppingCart $cart = null): Price
        {
            $this->carts[] = $cart?->id;

            return Price::fromGross($cart?->note === 'vip' ? 800 : 1000, 21.0, 'EUR');
        }

        public function isAvailableForPurchase(int $quantity, ?ShoppingCart $cart = null): bool
        {
            return true;
        }
    };

    $cart = ShoppingCart::completelyNew();
    $cart->update(['note' => 'vip']);
    $line = $cart->fresh()->add($purchasable, 1);

    expect($line->price_including_vat)->toBe(800)
        ->and($purchasable->carts)->toBe([$cart->id]);
});

it('signs a line with its purchasable type', function (): void {
    expect(ShoppingCartItem::signatureFor(Product::class, 1, CartItemType::Product, null))
        ->not->toBe(ShoppingCartItem::signatureFor(GiftCard::class, 1, CartItemType::Product, null))
        ->and(ShoppingCartItem::signatureFor(Product::class, 1, CartItemType::Product, null))
        ->toBe(ShoppingCartItem::signatureFor(Product::class, '1', CartItemType::Product, null));
});
