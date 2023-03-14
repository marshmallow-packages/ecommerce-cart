<?php

namespace Marshmallow\Ecommerce\Cart\Models;

use Illuminate\Support\Str;
use Marshmallow\Priceable\Price;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Database\Eloquent\Model;
use Marshmallow\Payable\Traits\Payable;
use Marshmallow\Product\Models\Product;
use Marshmallow\Addressable\Models\Address;
use Marshmallow\Ecommerce\Cart\Facades\Cart;
use Marshmallow\Ecommerce\Cart\Traits\Totals;
use Marshmallow\Addressable\Models\AddressType;
use Marshmallow\Ecommerce\Cart\Traits\PriceFormatter;
use Marshmallow\Ecommerce\Cart\Exceptions\DiscountException;

class ShoppingCart extends Model
{
    use Totals;
    use Payable;
    use PriceFormatter;

    const SESSION_KEY = 'cart';

    protected $guarded = [];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($cart) {

            $cart->display_id = $cart->max('display_id') + 1;

            $guard = Cart::getUserGuard();
            if (Auth::guard($guard)->check()) {
                $cart->connectUser(Auth::guard($guard)->user());
            }

            if (!$cart->getKey()) {
                $cart->{$cart->getKeyName()} = (string) Str::uuid();
            }

            $cart->hashed_ip_address = Hash::make(request()->ip());

            /**
             * Only create a prospect if its not provided.
             */
            if (!$cart->prospect_id) {
                $prospect = config('cart.models.prospect')::create([]);
                $cart->prospect_id = $prospect->id;
                $cart->customer_id = $prospect->getCustomer()?->id;
            }
        });
    }

    public function add(Product $product, float $quantity = 1): ShoppingCartItem
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

    public function addCustom(string $description, Price $price, string $type, bool $visible_in_cart = true, float $quantity = 1, Product $product = null, bool $should_combine_products = true): ShoppingCartItem
    {
        $cart = ($this->id) ? $this : config('cart.models.shopping_cart')::completelyNew();

        $method = $should_combine_products ? 'firstOrNew' : 'create';

        $cart_item = config('cart.models.shopping_cart_item')::{$method}([
            'shopping_cart_id' => $cart->id,
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
        return $this->items()->where('type', config('cart.models.shopping_cart_item')::TYPE_SHIPPING)->first();
    }

    public function shoppingCartContentChanged(ShoppingCartItem $item)
    {
        if (!$item->isShippingCost() && !$item->isDiscount()) {
            $this->calculateShippingCost();
            $this->recalculateDiscount();
        }
    }

    public function convertToInquiry()
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

    public function convertToOrder()
    {
        return config('cart.models.order')::createUniqueFromShoppingCart($this);
    }

    public function getTrackAndTraceId()
    {
        return $this->id;
    }

    public function getPayableDescription(): string
    {
        return __('Order') . " #{$this->display_id}";
    }

    public function getCustomer(): ?Model
    {
        return $this->getCustomerOrProspect();
    }

    public function getCustomerName(): ?string
    {
        $customer = $this->getCustomer();
        if ($customer && $name = $customer->getFullName()) {
            return $name;
        }

        return null;
    }

    public function getCustomerEmail(): ?string
    {
        $customer = $this->getCustomer();
        if ($customer && $email = $customer->email) {
            return $email;
        }

        return null;
    }

    public function getCustomerId(): ?string
    {
        $customer = $this->getCustomer();
        if ($customer && $customer_id = $customer->id) {
            return $customer_id;
        }

        return null;
    }

    public function getCustomerPayableExternalId(): ?string
    {
        $customer = $this->getCustomer();
        if ($customer && $external_id = $customer->payable_external_id) {
            return $external_id;
        }

        return null;
    }


    public function addCustomerIfExists(): void
    {
        if ($this->customer_id) {
            return;
        }

        $prospect = $this->prospect;
        $this->customer_id = $prospect->getCustomer()?->id;
        $this->saveQuietly();
    }

    public function hasExcludedShipping(): bool
    {
        return false;
    }

    /**
     * Protected
     */
    protected function calculateShippingCost()
    {
        $shipping_item = $this->items->where('type', config('cart.models.shopping_cart_item')::TYPE_SHIPPING)->first();
        if ($shipping_item) {
            $shipping_item->delete();
        }

        $shipping_method = config('cart.models.shipping_method')::calculateFromCart($this);

        if ($shipping_method) {
            $price = $shipping_method->getPriceHelper();
            $this->addCustom($shipping_method->name, $price, config('cart.models.shopping_cart_item')::TYPE_SHIPPING, false);
        }
    }

    protected function recalculateDiscount()
    {
        $shopping_cart_discount_item = $this->getDiscountItems()->first();
        if ($shopping_cart_discount_item) {
            $code = $shopping_cart_discount_item->description;
            $shopping_cart_discount_item->delete();
            $discount = config('cart.models.discount')::byCode($code);
            $this->addDiscount($discount);
        }
    }

    public function addDiscount(Discount $discount)
    {
        try {
            $discount->isAllowed($this);
            $price = $discount->calculateDiscountFromCart($this);
            $this->addCustom($discount->discount_code, $price, config('cart.models.shopping_cart_item')::TYPE_DISCOUNT, false);
        } catch (DiscountException $e) {
            return $e->getMessage();
        }
    }

    public function deleteDiscount()
    {
        $this->getDiscountItems()->each(function ($item) {
            $item->delete();
        });
    }

    public function connectUser($user)
    {
        $user = $user->fresh();
        $this->user_id = $user->id;
        $this->customer_id = ($user->customer) ? $user->customer->id : null;
        $this->update();

        if (method_exists($user, 'addresses')) {

            $default_shipping = $user->getDefaultAddress(AddressType::SHIPPING);
            if ($default_shipping && $this->doesNotHaveShippingAddress()) {
                $this->connectShippingAddress($default_shipping);
            }

            $default_invoice = $user->getDefaultAddress(AddressType::INVOICE);
            if ($default_invoice && $this->doesNotHaveInvoiceAddress()) {
                $this->connectInvoiceAddress($default_invoice);
            }
        }
    }

    public function disconnectUser()
    {
        $this->update([
            'user_id' => null,
            'customer_id' => null,
        ]);
    }

    public function getCustomerOrProspect()
    {
        return ($this->customer) ? $this->customer : $this->prospect;
    }

    public function doesNotHaveShippingAddress(): bool
    {
        return !$this->hasShippingAddress();
    }

    public function hasShippingAddress(): bool
    {
        return ($this->shipping_address_id !== null);
    }

    public function connectShippingAddress(Address $address)
    {
        $this->shipping_address_id = $address->id;
        $this->update();
    }

    public function doesNotHaveInvoiceAddress(): bool
    {
        return !$this->hasInvoiceAddress();
    }

    public function hasInvoiceAddress(): bool
    {
        return ($this->invoice_address_id !== null);
    }

    public function connectInvoiceAddress(Address $address)
    {
        $this->invoice_address_id = $address->id;
        $this->update();
    }

    /**
     * Statics
     */
    public static function getBySession(): ?ShoppingCart
    {
        $cart = self::find(
            session()->get(self::SESSION_KEY)
        );

        if ($cart && !$cart->user && !$cart->customer && !$cart->prospect) {
            return self::completelyNew();
        }

        return $cart;
    }

    public static function completelyNew(): ShoppingCart
    {
        $cart = self::create();
        session()->put(self::SESSION_KEY, $cart->id);
        return $cart;
    }

    public static function newWithSameProspect(ShoppingCart $cart): ShoppingCart
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
    public function authorized()
    {
        return (Hash::check(request()->ip(), $this->hashed_ip_address));
    }

    public function visibleItems()
    {
        return self::items()->visable()->get();
    }

    /**
     * Relationships
     */
    public function prospect()
    {
        return $this->belongsTo(config('cart.models.prospect'));
    }

    public function customer()
    {
        return $this->belongsTo(config('cart.models.customer'));
    }

    public function items()
    {
        return $this->hasMany(config('cart.models.shopping_cart_item'));
    }

    public function countries()
    {
        return config('cart.models.country')::ordered()->get();
    }

    public function user()
    {
        return $this->belongsTo(config('cart.models.user'));
    }

    public function shippingAddress()
    {
        return $this->belongsTo(config('cart.models.address'), 'shipping_address_id');
    }

    public function invoiceAddress()
    {
        return $this->belongsTo(config('cart.models.address'), 'invoice_address_id');
    }

    /**
     * Model setup
     */
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
