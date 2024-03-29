<?php

namespace Marshmallow\Ecommerce\Cart\Models;

use Marshmallow\Ecommerce\Cart\Cart;
use Illuminate\Database\Eloquent\Model;
use Marshmallow\Product\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Marshmallow\Ecommerce\Cart\Traits\ItemTotals;
use Marshmallow\Ecommerce\Cart\Models\ShoppingCart;
use Marshmallow\Ecommerce\Cart\Traits\PriceFormatter;

class ShoppingCartItem extends Model
{
    use PriceFormatter;
    use ItemTotals;

    protected $guarded = [];

    public const TYPE_PRODUCT = 'PRODUCT';
    public const TYPE_DISCOUNT = 'DISCOUNT';
    public const TYPE_SHIPPING = 'SHIPPING';

    protected static function boot()
    {
        parent::boot();

        static::created(function ($cart_item) {
            $cart_item->cart->shoppingCartContentChanged($cart_item);
        });

        static::updated(function ($cart_item) {
            $cart_item->cart->shoppingCartContentChanged($cart_item);
        });

        static::deleted(function ($cart_item) {
            $cart_item->cart->shoppingCartContentChanged($cart_item);
        });
    }

    /**
     * Publics
     */
    public function isShippingCost()
    {
        return ($this->type === self::TYPE_SHIPPING);
    }

    public function isDiscount()
    {
        return ($this->type === self::TYPE_DISCOUNT);
    }

    public function setQuantity(int $quantity): self
    {
        $this->update([
            'quantity' => $quantity,
        ]);

        return $this;
    }

    public function increaseQuantity(int $increase_with = 1): self
    {
        $this->update([
            'quantity' => $this->quantity + $increase_with,
        ]);

        return $this;
    }

    public function decreaseQuantity(int $decrease_with = 1): self
    {
        /**
         * Calculate the new quantity
         */
        $quantity = $this->quantity - $decrease_with;

        /**
         * Make sure we never get a value below zero.
         */
        $quantity = ($quantity <= 0) ? 1 : $quantity;
        $this->update([
            'quantity' => $quantity,
        ]);

        return $this;
    }

    /**
     * Statics
     */
    public static function add(ShoppingCart $cart, Product $product, int $quantity)
    {
        $cart->add($product, $quantity);
    }

    /**
     * Scopes
     */
    public function scopeVisable(Builder $builder)
    {
        $builder->where('visible_in_cart', true);
    }

    /**
     * Relationships
     */
    public function cart()
    {
        return $this->belongsTo(
            config('cart.models.shopping_cart'),
            'shopping_cart_id'
        );
    }

    public function product()
    {
        return $this->setConnection(Cart::$productConnection)
            ->belongsTo(
                config('cart.models.product'),
                'product_id'
            );
    }

    public function vatrate()
    {
        return $this->belongsTo(
            config('cart.models.vat_rate'),
            'vatrate_id'
        );
    }
}
