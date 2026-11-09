<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $preview = $this->routeIs('payments.preview');
        $correction = $this->routeIs('payments.correct') || ($preview && $this->filled('corrects_payment_id'));
        $rules = [
            'contract_id' => ['required', 'integer', 'exists:contracts,id'],
            'amount' => ['required', 'numeric', 'decimal:0,2', 'gt:0', 'max:999999999999.99'],
            'paid_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:1000-01-01', 'before_or_equal:'.today()->toDateString()],
            'method' => ['required', 'in:cash,transfer,wallet,other'], 'reference' => ['nullable', 'string', 'max:255'], 'notes' => ['nullable', 'string', 'max:5000'],
            'expected_schedule_version' => ['required', 'integer', 'min:1'],
            'expected_principal_remaining' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:999999999999999.99'],
            'expected_deposit_remaining' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:999999999999999.99'],
            'allocations' => ['required', 'array', 'min:1', 'max:602'],
            'allocations.*.due_id' => ['required', 'integer', 'distinct', 'exists:dues,id'],
            'allocations.*.amount' => ['required', 'numeric', 'decimal:0,2', 'gt:0', 'max:999999999999.99'],
            'allocations.*.expected_remaining' => ['required', 'numeric', 'decimal:0,2', 'gt:0', 'max:999999999999.99'],
            'reason' => [Rule::requiredIf($correction), 'nullable', 'string', 'max:1000'],
        ];
        if ($preview) {
            $rules['corrects_payment_id'] = ['nullable', 'integer', 'exists:payments,id'];
        } else {
            $rules['request_key'] = ['required', 'uuid'];
        }

        return $rules;
    }

    public function messages(): array
    {
        return ['amount.gt' => 'مبلغ الدفعة يجب أن يكون أكبر من صفر.', 'allocations.*.due_id.distinct' => 'اختر كل مستحق مرة واحدة فقط.', 'allocations.*.amount.gt' => 'المبلغ المخصص يجب أن يكون أكبر من صفر.'];
    }
}
