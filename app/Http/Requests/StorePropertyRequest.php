<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StorePropertyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title'             => 'required|string|max:255',
            'description'       => 'required|string',
            'address'           => 'required|string',
            'city'              => 'nullable|string|max:120',
            'status'            => 'nullable|string|in:available,rented,maintenance',
            'monthly_price'     => 'required|numeric|min:0',
            'area_m2'           => 'nullable|numeric|min:0',
            'num_bedrooms'      => 'nullable|integer|min:0',
            'num_bathrooms'     => 'nullable|integer|min:0',
            'included_services' => 'nullable|string',
            'lat'               => 'nullable|numeric',
            'lng'               => 'nullable|numeric',
            'accuracy'          => 'nullable|numeric',
            'user_id'           => 'nullable|integer|exists:users,id',
            'images'            => 'nullable|string',
            'publication_date'  => 'nullable|date',
        ];
    }
}
