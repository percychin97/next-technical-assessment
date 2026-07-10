<?php

namespace App\Http\Requests\Event;

use Illuminate\Foundation\Http\FormRequest;

class UpdateEventRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isVendor() ?? false;
    }

    public function rules(): array
    {
        return [
            'title'            => ['sometimes', 'string', 'max:255'],
            'description'      => ['sometimes', 'nullable', 'string'],
            'start_at_utc'     => ['sometimes', 'date'],
            'end_at_utc'       => ['sometimes', 'date', 'after:start_at_utc'],
            'display_timezone' => ['sometimes', 'string', 'timezone'],
        ];
    }
}
