<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PortalStudentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['school_sis_id' => ['required', 'integer'], 'crm_reference' => ['required', 'uuid'], 'sis_id' => ['nullable', 'integer', 'min:1'], 'first_name' => ['required', 'string', 'max:255'], 'last_name' => ['required', 'string', 'max:255'], 'grade_level' => ['nullable', 'integer'], 'application_reference' => ['nullable', 'string', 'max:255']];
    }

    public function messages(): array
    {
        return ['school_sis_id.required' => 'A school mapping is required.'];
    }
}
