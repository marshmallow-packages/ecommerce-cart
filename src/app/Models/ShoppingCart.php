<?php

namespace Marshmallow\Ecommerce\Cart\Models;

use Illuminate\Support\Str;
use Illuminate\Support\Facades\Hash;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Session;
use Marshmallow\Product\Models\Product;
use Marshmallow\Ecommerce\Cart\Models\Inquiry;
use Marshmallow\Ecommerce\Cart\Models\Prospect;
use Marshmallow\Datasets\Country\Models\Country;

class ShoppingCart extends Model
{
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

            // Maak ook gelijk een prospect aan zodat we hier altijd
            // een koppeling voor hebben.
            $prospect = Prospect::create([]);
            $cart->prospect_id = $prospect->id;
        });
    }

    public function add (Product $product, float $quantity = 1)
    {
        $this->items()->create([
            'product_id' => $product->id,
            'quantity' => $quantity,
        ]);
        return $this;
    }

    public function convertToInquiry ()
    {
        $inquiry = Inquiry::create([
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

    public static function completelyNew () : ShoppingCart
    {
        $cart = self::create();
        Session::put(self::SESSION_KEY, $cart->id);
        return $cart;
    }

    public static function newWithSameProspect (ShoppingCart $cart) : ShoppingCart
    {
        $new_cart = self::completelyNew();
        $new_cart->prospect_id = $cart->prospect_id;
        $new_cart->update();

        Session::put(self::SESSION_KEY, $new_cart->id);

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
        return $this->belongsTo(Prospect::class);
    }

    public function customer ()
    {
        return $this->belongsTo(Customer::class);
    }

    public function items ()
    {
        return $this->hasMany(ShoppingCartItem::class);
    }

    public function countries ()
    {
        return Country::ordered()->get();
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