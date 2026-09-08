<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PortalReconcileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['school_sis_id' => ['required', 'integer'], 'crm_reference' => ['required', 'uuid'], 'duplicate_uuid' => ['required', 'uuid']];
    }

    public function messages(): array
    {
        return ['school_sis_id.required' => 'A school mapping is required.'];
    }
}
