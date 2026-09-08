<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PortalInvoiceContextRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['school_sis_id' => ['required', 'integer'], 'student_refs' => ['required', 'array', 'min:1', 'max:100'], 'student_refs.*' => ['required', 'uuid', 'distinct'], 'invoice_uuids' => ['present', 'array', 'max:1000'], 'invoice_uuids.*' => ['required', 'uuid', 'distinct'], 'search' => ['nullable', 'string', 'max:100'], 'status' => ['nullable', 'in:all,outstanding,paid,past_due'], 'page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']];
    }

    public function messages(): array
    {
        return ['school_sis_id.required' => 'A school mapping is required.'];
    }
}
