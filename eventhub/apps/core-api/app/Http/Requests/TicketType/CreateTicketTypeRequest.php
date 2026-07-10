<?php

namespace App\Http\Requests\TicketType;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateTicketTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isVendor() ?? false;
    }

    public function rules(): array
    {
        return [
            'code'                         => ['required', 'string', 'max:50', 'alpha_dash'],
            'name'                         => ['required', 'string', 'max:255'],
            'category'                     => ['required', Rule::in(['early_bird', 'vip', 'general_admission', 'group_bundle'])],
            'price_minor'                  => ['required', 'integer', 'min:0'],
            'currency'                     => ['sometimes', 'string', 'size:3'],
            'capacity'                     => ['required_without:inventory_pool_id', 'integer', 'min:1'],
            'inventory_pool_id'            => ['sometimes', 'uuid', 'exists:ticket_inventory_pools,id'],
            'pool_name'                    => ['sometimes', 'string', 'max:100'],
            'admission_units_per_purchase' => ['sometimes', 'integer', 'min:1', 'max:20'],
            'sale_start_at_utc'            => ['nullable', 'date'],
            'sale_end_at_utc'              => ['nullable', 'date', 'after:sale_start_at_utc'],
            'is_active'                    => ['sometimes', 'boolean'],
        ];
    }
}
