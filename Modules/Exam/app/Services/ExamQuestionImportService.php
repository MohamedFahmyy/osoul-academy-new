<?php

namespace Modules\Exam\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Modules\Exam\Models\Exam;
use Modules\Exam\Models\ExamQuestion;
use Modules\Exam\Models\ExamQuestionOption;

class ExamQuestionImportService
{
    public function __construct(
        protected ExamQuestionService $questionService
    ) {}

    /**
     * Parse and import questions from a CSV file.
     *
     * @param Exam $exam
     * @param string $filePath
     * @param string $originalFilename
     * @param string $ipAddress
     * @return array{success: bool, imported: int, skipped: int, errors: array<string>}
     */
    public function import(Exam $exam, string $filePath, string $originalFilename, string $ipAddress): array
    {
        $startTime = microtime(true);
        $importedCount = 0;
        $skippedCount = 0;
        $errors = [];
        $validQuestions = [];

        if (($handle = fopen($filePath, 'r')) === false) {
            return [
                'success' => false,
                'imported' => 0,
                'skipped' => 0,
                'errors' => ['Unable to open the uploaded file.'],
            ];
        }

        // 1. Read and Validate Headers
        $headerLine = fgetcsv($handle, 2000, ',');
        if (!$headerLine) {
            fclose($handle);
            return [
                'success' => false,
                'imported' => 0,
                'skipped' => 0,
                'errors' => ['The uploaded CSV file is empty.'],
            ];
        }

        // Sanitize headers and remove UTF-8 BOM
        $headers = array_map([$this, 'sanitizeHeader'], $headerLine);
        $headerMap = array_flip($headers);

        // Required fields check
        $requiredFields = ['title', 'question_type', 'marks'];
        foreach ($requiredFields as $field) {
            if (!isset($headerMap[$field])) {
                fclose($handle);
                return [
                    'success' => false,
                    'imported' => 0,
                    'skipped' => 0,
                    'errors' => ["Invalid template. Missing required column '{$field}'. Please download the official CSV sample."],
                ];
            }
        }

        $rowNumber = 1; // Header row is 1
        $allowedTypes = ['multiple_choice', 'multiple_select', 'short_answer', 'true_false', 'matching', 'fill_blank', 'ordering', 'listening'];

        // 2. Parse and Validate Rows
        while (($data = fgetcsv($handle, 2000, ',')) !== false) {
            $rowNumber++;

            // Skip empty rows
            if (empty(array_filter($data))) {
                $skippedCount++;
                continue;
            }

            // Map row data using headers
            $row = [];
            foreach ($headerMap as $field => $index) {
                $row[$field] = isset($data[$index]) ? trim($data[$index]) : '';
            }

            // Map other columns if they exist
            for ($i = 1; $i <= 5; $i++) {
                $optName = "option_{$i}";
                if (isset($headerMap[$optName])) {
                    $row[$optName] = trim($data[$headerMap[$optName]]);
                } else {
                    $row[$optName] = '';
                }
            }
            if (isset($headerMap['correct_options'])) {
                $row['correct_options'] = trim($data[$headerMap['correct_options']]);
            } else {
                $row['correct_options'] = '';
            }

            // Normalize question type
            $qTypeRaw = str_replace(' ', '_', strtolower($row['question_type'] ?? ''));
            $row['question_type'] = $qTypeRaw;

            // Row validation
            $validator = Validator::make($row, [
                'title' => 'required|string|max:1000',
                'question_type' => 'required|string|in:' . implode(',', $allowedTypes),
                'marks' => 'required|numeric|min:0.1',
                'description' => 'nullable|string|max:5000',
            ], [
                'title.required' => 'Question title is missing.',
                'question_type.required' => 'Question type is missing.',
                'question_type.in' => "Invalid question type '{$row['question_type']}'.",
                'marks.required' => 'Question marks are missing.',
                'marks.numeric' => 'Marks must be a numeric value.',
                'marks.min' => 'Marks must be at least 0.1.',
            ]);

            // Custom checks for Multiple Choice/Select & True/False
            $validator->after(function ($validator) use ($row) {
                $type = $row['question_type'];

                if (in_array($type, ['multiple_choice', 'multiple_select'])) {
                    if (empty($row['option_1']) || empty($row['option_2'])) {
                        $validator->errors()->add('option_1', 'Multiple choice/select questions must have at least option_1 and option_2 populated.');
                    }

                    if (empty($row['correct_options'])) {
                        $validator->errors()->add('correct_options', 'Correct option index is required (e.g. 1 or 1,2).');
                    } else {
                        $correctIndices = array_map('trim', explode(',', $row['correct_options']));
                        foreach ($correctIndices as $val) {
                            if (!is_numeric($val) || intval($val) < 1 || intval($val) > 5) {
                                $validator->errors()->add('correct_options', "Correct option index '{$val}' is invalid (must be between 1 and 5).");
                            } elseif (empty($row['option_' . $val])) {
                                $validator->errors()->add('correct_options', "Correct option index '{$val}' refers to an empty option.");
                            }
                        }

                        if ($type === 'multiple_choice' && count($correctIndices) !== 1) {
                            $validator->errors()->add('correct_options', 'Multiple choice questions must have exactly 1 correct option.');
                        }
                    }
                } elseif ($type === 'true_false') {
                    // Populate options automatically
                    $row['option_1'] = 'True';
                    $row['option_2'] = 'False';
                    
                    if (empty($row['correct_options'])) {
                        $validator->errors()->add('correct_options', 'Correct option index is required (1 for True, 2 for False).');
                    } else {
                        $correctVal = intval(trim($row['correct_options']));
                        if ($correctVal !== 1 && $correctVal !== 2) {
                            $validator->errors()->add('correct_options', 'True/False questions must have correct_options set to 1 (True) or 2 (False).');
                        }
                    }
                }
            });

            if ($validator->fails()) {
                foreach ($validator->errors()->all() as $msg) {
                    $errors[] = "Row {$rowNumber}: {$msg}";
                }
                continue;
            }

            // Build question and options payload
            $questionData = [
                'exam_id' => $exam->id,
                'question_type' => $row['question_type'],
                'title' => $row['title'],
                'description' => $row['description'] ?? '',
                'marks' => floatval($row['marks']),
                'question_options' => [],
            ];

            // Options mapping for choice questions
            if (in_array($row['question_type'], ['multiple_choice', 'multiple_select', 'true_false'])) {
                $correctIndices = array_map('intval', array_map('trim', explode(',', $row['correct_options'])));

                for ($i = 1; $i <= 5; $i++) {
                    $optKey = "option_{$i}";
                    $optText = ($row['question_type'] === 'true_false') 
                        ? ($i === 1 ? 'True' : ($i === 2 ? 'False' : ''))
                        : ($row[$optKey] ?? '');

                    if (!empty($optText)) {
                        $questionData['question_options'][] = [
                            'option_text' => $optText,
                            'is_correct' => in_array($i, $correctIndices),
                            'sort' => $i,
                        ];
                    }
                }
            }

            $validQuestions[] = $questionData;
        }
        fclose($handle);

        // 3. Rollback completely if there are any validation errors
        if (!empty($errors)) {
            return [
                'success' => false,
                'imported' => 0,
                'skipped' => $skippedCount,
                'errors' => $errors,
            ];
        }

        if (empty($validQuestions)) {
            return [
                'success' => false,
                'imported' => 0,
                'skipped' => $skippedCount,
                'errors' => ['No valid questions found in the CSV file.'],
            ];
        }

        // 4. Save imported questions in database inside a Transaction
        DB::transaction(function () use ($exam, $validQuestions, &$importedCount) {
            // Processing in chunks of 100 to reduce memory overhead
            $chunks = array_chunk($validQuestions, 100);
            foreach ($chunks as $chunk) {
                foreach ($chunk as $qData) {
                    $this->questionService->createQuestion($qData);
                    $importedCount++;
                }
            }
        });

        // 5. Audit Logging
        $duration = round(microtime(true) - $startTime, 4);
        Log::info('Exam questions imported successfully', [
            'exam_id' => $exam->id,
            'user_id' => Auth::id(),
            'ip' => $ipAddress,
            'filename' => $originalFilename,
            'questions_imported' => $importedCount,
            'failed_rows' => count($errors),
            'duration_seconds' => $duration,
        ]);

        return [
            'success' => true,
            'imported' => $importedCount,
            'skipped' => $skippedCount,
            'errors' => [],
        ];
    }

    /**
     * Sanitize header names and strip UTF-8 BOM
     */
    private function sanitizeHeader(string $header): string
    {
        $header = str_replace("\xEF\xBB\xBF", '', $header);
        return trim(strtolower($header));
    }
}
