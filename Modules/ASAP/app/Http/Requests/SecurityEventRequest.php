<?php

namespace Modules\ASAP\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SecurityEventRequest extends FormRequest
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
            'event_uuid' => 'required|uuid',
            'event_code' => 'required|string|max:255',
            'payload' => 'nullable|array',
            'severity' => 'required|string|in:info,warning,critical,terminate',
            'source' => 'required|string|in:client,agent,system',
            'category' => 'required|string|in:window,process,network,device,system',
            'client_sequence' => 'required|integer|min:0',
            'correlation_id' => 'nullable|uuid',
            'signature' => 'required|string|max:255',
            'occurred_at' => 'required|date',
        ];
    }
}
