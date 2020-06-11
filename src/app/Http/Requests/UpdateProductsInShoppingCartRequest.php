<?php

namespace Marshmallow\Ecommerce\Cart\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProductsInShoppingCartRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize()
    {
        return request()->cart->authorized();
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'data.*.product_id' => 'required|numeric|distinct|exists:shopping_cart_items,product_id|exists:products,id',
            'data.*.quantity' => 'required|integer|min:1'
        ];
    }
}
