<?php

namespace Modules\ASAP\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class TelemetryHeartbeatRequest extends FormRequest
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
            'session_id' => 'required|uuid|exists:asap_sessions,id',
            'session_key_id' => 'required|string|max:255',
            'payload' => 'required|array',
            'payload.is_focused' => 'required|boolean',
            'payload.is_fullscreen' => 'required|boolean',
            'payload.sequence_number' => 'nullable|integer|min:0',
            'payload.timestamp' => 'nullable|integer',
            'payload.nonce' => 'nullable|string|max:64',
            'payload.status' => 'nullable|string|max:50',
            'payload.occurred_at' => 'nullable|string|max:100',
            'signature' => 'required|string|max:255',
        ];
    }
}
