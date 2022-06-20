<?php

namespace Marshmallow\Ecommerce\Cart\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Marshmallow\Ecommerce\Cart\Facades\Cart;

class UpdateCustomerDataRequest extends FormRequest
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
            'first_name' => 'required|string',
            'last_name' => 'required',
            'company_name' => 'required',
            'address' => 'required',
            'country_id' => 'required|exists:countries,id',
            'email' => 'required|email',
            'phone_number' => 'required',
        ];
    }
}
