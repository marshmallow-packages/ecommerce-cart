<?php

namespace Marshmallow\Ecommerce\Cart\Models;

use Illuminate\Database\Eloquent\Model;
use Marshmallow\Product\Models\Product;
use Marshmallow\Ecommerce\Cart\Models\ShoppingCart;

class ShoppingCartItem extends Model
{
    protected $fillable = ['shopping_cart_id', 'product_id', 'quantity'];

    public static function add (ShoppingCart $cart, Product $product, int $quantity)
    {
        $cart_item = self::firstOrNew([
            'shopping_cart_id' => $cart->id,
            'product_id' => $product->id,
        ]);

        $cart_item->quantity = ($cart_item->quantity + $quantity);
        $cart_item->save();
    }

    public function cart ()
    {
        return $this->belongsTo(ShoppingCart::class);
    }

    public function product ()
    {
        return $this->belongsTo(Product::class);
    }
}
