<?php

namespace Marshmallow\Ecommerce\Cart\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Marshmallow\Ecommerce\Cart\Facades\Cart;

class AddProductsToShoppingCartRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize()
    {
        return Cart::getFromRequest()->authorized();
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'data.*.product_id' => 'required|numeric|distinct|exists:products,id',
            'data.*.quantity' => 'required|integer|min:1'
        ];
    }
}
