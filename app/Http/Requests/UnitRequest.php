<?php

namespace App\Http\Requests;

use App\Models\Unit;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UnitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->number)) {
            $this->merge(['number' => Unit::normalizeNumber($this->number)]);
        }
    }

    public function rules(): array
    {
        return [
            'building_id' => ['required', 'integer', 'exists:buildings,id'],
            'number' => ['required', 'string', 'max:50', Rule::unique('units')->where('building_id', $this->input('building_id'))->ignore($this->route('unit')?->id)],
            'floor' => ['required', 'integer', 'min:-10', 'max:200'],
            'type' => ['required', Rule::in(['apartment', 'shop', 'office', 'other'])],
            'area' => ['nullable', 'numeric', 'decimal:0,2', 'gt:0', 'max:99999999.99'],
            'asking_sale_price' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:999999999999.99'],
            'asking_rent_price' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:999999999999.99'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'status' => ['prohibited'],
        ];
    }

    public function messages(): array
    {
        return [
            'number.unique' => 'رقم الوحدة مستخدم بالفعل داخل هذا المبنى.',
            'building_id.exists' => 'اختر مبنى موجودًا.',
            'status.prohibited' => 'حالة الوحدة تتحدد من العقود ولا يمكن تغييرها يدويًا.',
            'area.gt' => 'المساحة يجب أن تكون أكبر من صفر.',
            '*.decimal' => 'استخدم منزلتين عشريتين على الأكثر.',
        ];
    }
}
