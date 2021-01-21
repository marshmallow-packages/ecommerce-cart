<?php

namespace Marshmallow\Ecommerce\Cart\Models;

use Illuminate\Database\Eloquent\Model;
use Marshmallow\Product\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Marshmallow\Ecommerce\Cart\Models\ShoppingCart;

class ShoppingCartItem extends Model
{
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

    /**
     * Statics
     */
    public static function add (ShoppingCart $cart, Product $product, int $quantity)
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
    public function cart ()
    {
        return $this->belongsTo(
            config('cart.models.shopping_cart'),
            'shopping_cart_id'
        );
    }

    public function product ()
    {
        return $this->belongsTo(
            config('cart.models.product'),
            'product_id'
        );
    }
}
