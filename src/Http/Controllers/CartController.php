<?php

namespace Marshmallow\Ecommerce\Cart\Http\Controllers;

use Illuminate\Routing\Controller;
use Marshmallow\Ecommerce\Cart\Models\ShoppingCart;
use Marshmallow\Ecommerce\Cart\Models\ShoppingCartItem;
use Marshmallow\Ecommerce\Cart\Http\Middleware\CartMiddleware;
use Marshmallow\Ecommerce\Cart\Http\Requests\ShoppingCartRequest;
use Marshmallow\Ecommerce\Cart\Http\Resources\ShoppingCartResource;
use Marshmallow\Ecommerce\Cart\Http\Requests\UpdateCustomerDataRequest;
use Marshmallow\Ecommerce\Cart\Http\Requests\AddProductsToShoppingCartRequest;
use Marshmallow\Ecommerce\Cart\Http\Requests\UpdateProductsInShoppingCartRequest;
use Marshmallow\Ecommerce\Cart\Http\Requests\DeleteProductFromShoppingCartRequest;

class CartController extends Controller
{
	public function __construct ()
	{
		$this->middleware([
            config('cart.middleware.cart'),
		]);
	}

    public function index(ShoppingCart $cart, ShoppingCartRequest $request)
    {
        return new ShoppingCartResource($cart);
    }

    public function post (ShoppingCart $cart, AddProductsToShoppingCartRequest $request)
    {
        foreach ($request->all()['data'] as $item) {

            config('cart.models.shopping_cart_item')::add(
                $cart,
                config('cart.models.product')::find($item['product_id']),
                $item['quantity']
            );

        }
        return new ShoppingCartResource($cart);
    }

    public function update (ShoppingCart $cart, UpdateProductsInShoppingCartRequest $request)
    {
        foreach ($request->all()['data'] as $item) {

            $shopping_cart_item = $cart->items()->where('product_id', $item['product_id'])->first();
            $shopping_cart_item->quantity = $item['quantity'];
            $shopping_cart_item->save();

        }
        return new ShoppingCartResource($cart);
    }
    public function clear (ShoppingCart $cart, ShoppingCartRequest $request)
    {
        $cart->items()->delete();
        return new ShoppingCartResource($cart);
    }

    public function deleteItem (ShoppingCart $cart, ShoppingCartItem $item, DeleteProductFromShoppingCartRequest $request)
    {
        $item->delete();
        return new ShoppingCartResource($cart);
    }

    /**
     * Update the customer data
     *
     * @return;
     */
    public function put (ShoppingCart $cart, UpdateCustomerDataRequest $request)
    {
        $cart->prospect->update(
            $request->only($cart->prospect->getFillable())
        );

        $cart->note = $request->message;
        $cart->confirmed_at = now();
        $cart->update();

        config('cart.jobs.process_inquiry_request')::dispatch($cart);

        return new ShoppingCartToInquiryResource($cart);
    }

    public function completed (ShoppingCart $cart)
    {
        // Check for access
        if (!$cart->authorized()) {
            abort(403);
        }

        return view('order-complete')->with([
            'completed_cart' => $cart
        ]);
    }

}
