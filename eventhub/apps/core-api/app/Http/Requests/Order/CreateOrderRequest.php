<?php

namespace App\Http\Requests\Order;

use Illuminate\Foundation\Http\FormRequest;

class CreateOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAttendee() ?? false;
    }

    public function rules(): array
    {
        return [
            'event_id'               => ['required', 'uuid', 'exists:events,id'],
            'items'                  => ['required', 'array', 'min:1', 'max:10'],
            'items.*.ticket_type_id' => ['required', 'uuid', 'exists:ticket_types,id'],
            'items.*.quantity'       => ['required', 'integer', 'min:1', 'max:20'],
        ];
    }

    public function messages(): array
    {
        return [
            'items.*.ticket_type_id.exists' => 'One or more ticket types do not exist.',
            'items.*.quantity.min'           => 'Quantity must be at least 1.',
            'items.*.quantity.max'           => 'Maximum 20 tickets per type per order.',
        ];
    }
}
