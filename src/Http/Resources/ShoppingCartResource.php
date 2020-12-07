<?php

namespace Marshmallow\Ecommerce\Cart\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;
use Marshmallow\Datasets\Country\Models\Country;
use Marshmallow\Ecommerce\Cart\Http\Resources\ProspectResource;

class ShoppingCartResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array
     */
    public function toArray($request)
    {
        return [
            'cart' => [
                'product_count' => $this->items->count(),
            ],
            'prospect' => new ProspectResource($this),
            // 'view' => (string) view('layouts.partials.cart')->with([
                // 'cart' => $this,
                // 'countries' => Country::ordered()->get()
            // ])
        ];
    }
}
