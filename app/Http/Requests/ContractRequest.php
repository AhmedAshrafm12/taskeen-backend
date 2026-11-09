<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ContractRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $installments = $this->type === 'sale' && $this->sale_mode === 'installments';
        $periodic = $installments || $this->type === 'rental';

        return [
            'request_key' => ['required', 'uuid'], 'unit_id' => ['required', 'integer', 'exists:units,id'],
            'customer_id' => ['required', 'integer', 'exists:customers,id'], 'type' => ['required', 'in:sale,rental'],
            'status' => ['required', 'in:draft,active'], 'sale_mode' => [Rule::requiredIf($this->type === 'sale'), 'nullable', 'in:cash,installments', 'prohibited_if:type,rental'],
            'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['required_if:type,rental', 'nullable', 'date_format:Y-m-d', 'after_or_equal:start_date', 'prohibited_if:type,sale'],
            'amount' => ['required', 'numeric', 'decimal:0,2', 'gt:0', 'max:999999999999.99'],
            'down_payment' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'lte:amount'],
            'security_deposit' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:999999999999.99'],
            'frequency' => [Rule::requiredIf($periodic), 'nullable', 'in:monthly,quarterly,yearly'],
            'installments_count' => [Rule::requiredIf($installments), 'nullable', 'integer', 'min:1', 'max:600'],
            'first_due_date' => [Rule::requiredIf($installments), 'nullable', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'renewed_from_id' => ['nullable', 'integer', 'exists:contracts,id'], 'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    public function messages(): array
    {
        return ['amount.gt' => 'قيمة العقد يجب أن تكون أكبر من صفر.', 'down_payment.lte' => 'المقدم لا يزيد عن قيمة البيع.'];
    }
}
