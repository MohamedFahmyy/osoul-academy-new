<?php

namespace Modules\Exam\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Modules\Exam\Models\Exam;

class ImportExamQuestionsRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $examId = $this->route('exam');
        if (!$examId) {
            return false;
        }

        $exam = Exam::find($examId);
        if (!$exam) {
            return false;
        }

        // Admins can manage all, Instructors can only manage their own exams
        if (isAdmin()) {
            return true;
        }

        return Auth::user()?->instructor_id === $exam->instructor_id;
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'file' => [
                'required',
                'file',
                'mimetypes:text/plain,text/csv,text/comma-separated-values,application/csv,application/excel,application/vnd.ms-excel',
                'max:10240', // Max 10MB
            ],
        ];
    }

    /**
     * Custom error messages
     */
    public function messages(): array
    {
        return [
            'file.required' => 'Please select a CSV file to upload.',
            'file.mimetypes' => 'The uploaded file must be a valid CSV file.',
            'file.max' => 'The CSV file size must not exceed 10MB.',
        ];
    }
}
