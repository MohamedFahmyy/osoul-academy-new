<?php

namespace Modules\ASAP\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SessionStartRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'device_uuid' => 'required|string|max:255',
            'device_name' => 'required|string|max:255',
            'operating_system' => 'required|string|max:255',
            'hardware_hash' => 'required|string|max:255',
            'hardware_version' => 'required|string|max:255',
            'exam_id' => 'required|exists:exams,id',
        ];
    }
}
