<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (! $this->has('type')) {
            $this->merge(['type' => 'collections']);
        }
        if (! $this->has('date_from')) {
            $this->merge(['date_from' => today()->startOfMonth()->toDateString()]);
        }
        if (! $this->has('date_to')) {
            $this->merge(['date_to' => today()->toDateString()]);
        }
    }

    public function rules(): array
    {
        return [
            'type' => ['required', 'in:collections,dues,contracts'],
            'date_from' => ['required', 'date_format:Y-m-d', 'after_or_equal:1000-01-01'],
            'date_to' => ['required', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'building_id' => ['nullable', 'integer', 'exists:buildings,id'], 'page' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
