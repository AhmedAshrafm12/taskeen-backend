<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class BuildingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'address' => ['required', 'string', 'max:500'],
            'floors' => ['nullable', 'integer', 'min:1', 'max:200'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'اكتب اسم المبنى.', 'name.max' => 'اسم المبنى لا يزيد عن ١٥٠ حرفًا.',
            'address.required' => 'اكتب عنوان المبنى.', 'address.max' => 'العنوان لا يزيد عن ٥٠٠ حرف.',
            'floors.integer' => 'عدد الطوابق يجب أن يكون عددًا صحيحًا.',
            'floors.min' => 'عدد الطوابق لا يقل عن واحد.', 'floors.max' => 'عدد الطوابق لا يزيد عن ٢٠٠.',
            'notes.max' => 'الملاحظات لا تزيد عن ٥٠٠٠ حرف.',
        ];
    }
}
