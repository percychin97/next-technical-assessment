<?php

namespace App\Http\Requests\TicketType;

use Illuminate\Foundation\Http\FormRequest;

class UpdateTicketTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isVendor() ?? false;
    }

    public function rules(): array
    {
        return [
            'name'              => ['sometimes', 'string', 'max:255'],
            'price_minor'       => ['sometimes', 'integer', 'min:0'],
            'sale_start_at_utc' => ['sometimes', 'nullable', 'date'],
            'sale_end_at_utc'   => ['sometimes', 'nullable', 'date', 'after:sale_start_at_utc'],
            'is_active'         => ['sometimes', 'boolean'],
        ];
    }
}
