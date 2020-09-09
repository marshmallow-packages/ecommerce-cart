<?php

namespace Marshmallow\Ecommerce\Cart\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class ShoppingCartToInquiryResource extends JsonResource
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
                'track_and_trace' => $this->getTrackAndTraceId()
            ],
            'view' => 'ShoppingCartToInquiryResource::inquirySuccessMessage',
        ];
    }
}
