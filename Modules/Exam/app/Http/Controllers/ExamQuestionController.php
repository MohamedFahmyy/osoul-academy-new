<?php

namespace Modules\Exam\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Exam\Http\Requests\ExamQuestionRequest;
use Modules\Exam\Http\Requests\ImportExamQuestionsRequest;
use Modules\Exam\Models\Exam;
use Modules\Exam\Models\ExamQuestion;
use Modules\Exam\Services\ExamQuestionService;
use Modules\Exam\Services\ExamQuestionImportService;

class ExamQuestionController extends Controller
{
    public function __construct(
        protected ExamQuestionService $question,
        protected ExamQuestionImportService $importService
    ) {}

    /**
     * Store a newly created question
     */
    public function store(ExamQuestionRequest $request)
    {
        $this->question->createQuestion($request->validated());

        return back()->with('success', 'Question created successfully.');
    }

    /**
     * Update the specified question
     */
    public function update(ExamQuestionRequest $request, string $id)
    {
        $this->question->updateQuestion($id, $request->all());

        return back()->with('success', 'Question updated successfully.');
    }

    /**
     * Remove the specified question
     */
    public function destroy(string $id)
    {
        $this->question->deleteQuestion($id);

        return back()->with('success', 'Question deleted successfully.');
    }

    /**
     * Reorder questions
     */
    public function reorder(Request $request)
    {
        $this->question->updateSortValues('exam_questions', $request->sortedData);

        return back()->with('success', 'Questions reordered successfully.');
    }

    /**
     * Duplicate a question
     */
    public function duplicate(ExamQuestion $question)
    {
        $this->question->duplicateQuestion($question);

        return back()->with('success', 'Question duplicated successfully.');
    }

    /**
     * Bulk import questions from CSV
     */
    public function import(ImportExamQuestionsRequest $request, string $examId)
    {
        $exam = Exam::findOrFail($examId);
        $file = $request->file('file');
        
        $result = $this->importService->import(
            $exam, 
            $file->getRealPath(), 
            $file->getClientOriginalName(), 
            $request->ip()
        );

        if (!$result['success']) {
            return back()->withErrors(['import_errors' => $result['errors']]);
        }

        $msg = "Successfully imported {$result['imported']} questions.";
        if ($result['skipped'] > 0) {
            $msg .= " Skipped {$result['skipped']} empty rows.";
        }

        return back()->with('success', $msg);
    }

    /**
     * Download sample CSV template
     */
    public function downloadSample()
    {
        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="exam_questions_sample.csv"',
        ];

        $callback = function () {
            $file = fopen('php://output', 'w');
            
            // CSV Headers
            fputcsv($file, [
                'title',
                'question_type',
                'marks',
                'description',
                'option_1',
                'option_2',
                'option_3',
                'option_4',
                'option_5',
                'correct_options'
            ]);

            // Sample MCQ Question
            fputcsv($file, [
                'What is the capital of France?',
                'multiple_choice',
                '10',
                'Choose the correct capital city.',
                'London',
                'Paris',
                'Berlin',
                'Madrid',
                '',
                '2'
            ]);

            // Sample Multiple Select Question
            fputcsv($file, [
                'Which of the following are programming languages?',
                'multiple_select',
                '15',
                'Select all that apply.',
                'Python',
                'HTML',
                'TypeScript',
                'CSS',
                '',
                '1,3'
            ]);

            // Sample True/False Question
            fputcsv($file, [
                'The Earth is flat.',
                'true_false',
                '5',
                'Determine if the statement is true or false.',
                '',
                '',
                '',
                '',
                '',
                '2' // option 2 is False
            ]);

            // Sample Short Answer Question
            fputcsv($file, [
                'Explain the difference between compiler and interpreter.',
                'short_answer',
                '20',
                'Provide a brief explanation.',
                '',
                '',
                '',
                '',
                '',
                ''
            ]);

            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }
}
