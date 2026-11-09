<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ScheduleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $rules = [
            'mode' => ['required', 'in:automatic,custom'],
            'entries' => ['exclude_unless:mode,custom', 'required_if:mode,custom', 'array', 'min:1', 'max:600'],
            'entries.*.due_date' => ['required', 'date_format:Y-m-d'],
            'entries.*.amount' => ['required', 'numeric', 'decimal:0,2', 'gt:0', 'max:999999999999.99'],
        ];
        if ($this->routeIs('schedule.preview')) {
            $rules['termination_date'] = ['nullable', 'date_format:Y-m-d'];
        }
        if (! $this->routeIs('schedule.preview')) {
            $rules = array_merge($rules, [
                'reason' => ['required', 'string', 'max:1000'], 'expected_version' => ['required', 'integer', 'min:0'], 'request_key' => ['required', 'uuid'],
            ]);
        }

        return $rules;
    }

    public function messages(): array
    {
        return ['entries.*.amount.gt' => 'قيمة كل استحقاق يجب أن تكون أكبر من صفر.', 'entries.min' => 'أضف استحقاقًا واحدًا على الأقل.', 'entries.max' => 'الحد الأقصى ٦٠٠ استحقاق.'];
    }
}
