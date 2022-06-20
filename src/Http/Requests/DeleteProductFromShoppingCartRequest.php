<?php

namespace Marshmallow\Ecommerce\Cart\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Marshmallow\Ecommerce\Cart\Facades\Cart;

class DeleteProductFromShoppingCartRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize()
    {
        $cart = Cart::getFromRequest();
        if (!$cart->authorized()) {
            return false;
        }

        if ($cart->id !== request()->item->shopping_cart_id) {
            return false;
        }

        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            //
        ];
    }
}
