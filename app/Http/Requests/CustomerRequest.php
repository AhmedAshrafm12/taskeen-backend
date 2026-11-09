<?php

namespace App\Http\Requests;

use App\Models\Customer;
use Illuminate\Foundation\Http\FormRequest;

class CustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->phone)) {
            $this->merge(['phone' => Customer::normalizePhone($this->phone)]);
        }
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'phone' => ['required', 'string', 'max:16', 'regex:/^\+[1-9][0-9]{7,14}$/'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:500'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'allow_duplicate_phone' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return ['phone.regex' => 'اكتب رقمًا مصريًا مثل 01012345678 أو رقمًا دوليًا يبدأ بكود الدولة مثل +201012345678.'];
    }
}
