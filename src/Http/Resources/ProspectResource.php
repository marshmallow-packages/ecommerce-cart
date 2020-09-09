<?php

namespace Marshmallow\Ecommerce\Cart\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class ProspectResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array
     */
    public function toArray($request)
    {
        $prospect = $this->prospect;
        
        if (!$prospect) {
            return [];
        }

        return [
            'first_name' => $prospect->first_name,
            'last_name' => $prospect->last_name,
            'company_name' => $prospect->company_name,
            'address' => $prospect->address,
            'country' => $prospect->country,
            'email' => $prospect->email,
            'phone_number' => $prospect->phone_number,
        ];
    }
}
