<?php

namespace Marshmallow\Ecommerce\Cart\Models;

use Illuminate\Support\Str;
use Marshmallow\Priceable\Price;
use Illuminate\Support\Facades\Hash;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Session;
use Marshmallow\Product\Models\Product;
use Marshmallow\Ecommerce\Cart\Models\Inquiry;
use Marshmallow\Ecommerce\Cart\Models\Prospect;
use Marshmallow\Datasets\Country\Models\Country;
use Marshmallow\Ecommerce\Cart\Traits\CartTotals;
use Marshmallow\Ecommerce\Cart\Models\ShippingMethod;

class ShoppingCart extends Model
{
    use CartTotals;

    const SESSION_KEY = 'cart';

    protected $fillable = [
        'hashed_ip_address',
        'note',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($cart) {
            if (! $cart->getKey()) {
                $cart->{$cart->getKeyName()} = (string) Str::uuid();
            }

            $cart->hashed_ip_address = Hash::make(request()->ip());

            /**
             * Only create a prospect if its not provided.
             */
            if (!$cart->prospect_id) {
                $prospect = config('cart.models.prospect')::create([]);
                $cart->prospect_id = $prospect->id;
            }
        });
    }

    public function add (Product $product, float $quantity = 1): ShoppingCartItem
    {
        return $this->addCustom(
            $product->fullname(),
            $product->getPriceHelper(),
            ShoppingCartItem::TYPE_PRODUCT,
            true,
            $quantity,
            $product
        );
    }

    public function addCustom (string $description, Price $price, string $type, bool $visible_in_cart = true, float $quantity = 1, Product $product = null): ShoppingCartItem
    {
        $cart_item = ShoppingCartItem::firstOrNew([
            'shopping_cart_id' => $this->id,
            'product_id' => ($product) ? $product->id : null,
            'vatrate_id' => $price->vatrate->id,
            'currency_id' => $price->currency->id,
            'description' => $description,
            'type' => $type,
            'display_price' => $price->display_amount,
            'price_excluding_vat' => $price->price_excluding_vat,
            'price_including_vat' => $price->price_including_vat,
            'vat_amount' => $price->vat_amount,
            'visible_in_cart' => $visible_in_cart,
        ]);

        $cart_item->quantity = ($cart_item->quantity + $quantity);
        $cart_item->save();

        return $cart_item;
    }

    public function getShippingItem()
    {
        return $this->items()->where('type', ShoppingCartItem::TYPE_SHIPPING)->first();
    }

    public function shoppingCartContentChanged(ShoppingCartItem $item)
    {
        if (! $item->isShippingCost()) {
            $this->calculateShippingCost();
        }
    }

    public function convertToInquiry ()
    {
        $inquiry = config('cart.models.inquiry')::create([
            'prospect_id' => $this->prospect_id,
            'note' => $this->note,
            'shopping_cart_id' => $this->id,
        ]);

        foreach ($this->items as $item) {
            $inquiry->items()->create([
                'product_id' => $item->product_id,
                'quantity' => $item->quantity,
            ]);
        }

        return $inquiry;
    }

    public function getTrackAndTraceId ()
    {
        return $this->id;
    }

    /**
     * Protected
     */
    protected function calculateShippingCost()
    {
        $shipping_item = $this->items->where('type', ShoppingCartItem::TYPE_SHIPPING)->first();
        if ($shipping_item) {
            $shipping_item->delete();
        }

        $shipping_method = ShippingMethod::calculateFromCart($this);

        if ($shipping_method) {
            $price = $shipping_method->getPriceHelper();
            $this->addCustom($shipping_method->name, $price, ShoppingCartItem::TYPE_SHIPPING, false);
        }
    }

    /**
     * Statics
     */
    public static function getBySession(): ?ShoppingCart
    {
        return self::find(
            session()->get(self::SESSION_KEY)
        );
    }

    public static function completelyNew () : ShoppingCart
    {
        $cart = self::create();
        session()->put(self::SESSION_KEY, $cart->id);
        return $cart;
    }

    public static function newWithSameProspect (ShoppingCart $cart) : ShoppingCart
    {
        $new_cart = self::completelyNew();
        $new_cart->prospect_id = $cart->prospect_id;
        $new_cart->update();

        session()->put(self::SESSION_KEY, $new_cart->id);

        return $new_cart;
    }

    /*
     * Deze check wordt uitgevoerd door de cart resources.
     * Voor nu checken we alleen op gehashte ip addressen, in de
     * toekomst kan hier misschien een user check bij komen.
     */
    public function authorized ()
    {
        return (Hash::check(request()->ip(), $this->hashed_ip_address));
    }

    public function prospect ()
    {
        return $this->belongsTo(config('cart.models.prospect'));
    }

    public function customer ()
    {
        return $this->belongsTo(config('cart.models.customer'));
    }

    public function items ()
    {
        return $this->hasMany(config('cart.models.shopping_cart_item'));
    }

    public function countries ()
    {
        return config('cart.models.country')::ordered()->get();
    }

    public function getIncrementing()
    {
        return false;
    }

    public function getKeyType()
    {
        return 'string';
    }

    public function getRouteKeyName()
    {
        return 'id';
    }
}
