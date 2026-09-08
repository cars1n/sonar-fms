<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PortalNotificationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('invoice'));
    }

    public function rules(): array
    {
        return ['action' => ['required', 'in:cancel,resend']];
    }

    public function messages(): array
    {
        return ['action.in' => 'Choose cancel or resend.'];
    }
}
