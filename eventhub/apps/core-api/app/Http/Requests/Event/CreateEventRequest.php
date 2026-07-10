<?php

namespace App\Http\Requests\Event;

use Illuminate\Foundation\Http\FormRequest;

class CreateEventRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isVendor() ?? false;
    }

    public function rules(): array
    {
        return [
            'title'            => ['required', 'string', 'max:255'],
            'description'      => ['nullable', 'string'],
            'start_at_utc'     => ['required', 'date', 'after:now'],
            'end_at_utc'       => ['required', 'date', 'after:start_at_utc'],
            'display_timezone' => ['sometimes', 'string', 'timezone'],
        ];
    }
}
