<?php

use App\Models\User;
use App\Models\Instructor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Modules\Exam\Models\ExamCategory;
use Modules\Exam\Models\Exam;
use Modules\Exam\Models\ExamQuestion;
use Modules\Exam\Models\ExamQuestionOption;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->admin = User::factory()->create(['role' => 'admin']);
    
    // Create instructor user & model
    $this->instructorUser = User::factory()->create(['role' => 'instructor']);
    $this->instructor = Instructor::create([
        'user_id' => $this->instructorUser->id,
        'status' => 'approved',
        'designation' => 'Instructor',
        'skills' => ['programming'],
        'biography' => 'Bio',
        'resume' => 'Resume',
    ]);
    $this->instructorUser->update(['instructor_id' => $this->instructor->id]);
    
    // Other instructor for authorization tests
    $this->otherInstructorUser = User::factory()->create(['role' => 'instructor']);
    $this->otherInstructor = Instructor::create([
        'user_id' => $this->otherInstructorUser->id,
        'status' => 'approved',
        'designation' => 'Other Instructor',
        'skills' => ['programming'],
        'biography' => 'Bio',
        'resume' => 'Resume',
    ]);
    $this->otherInstructorUser->update(['instructor_id' => $this->otherInstructor->id]);

    // Create Category
    $this->category = ExamCategory::create([
        'title' => 'Programming',
        'slug' => 'programming',
        'icon' => 'folder',
        'status' => true,
    ]);

    // Create Exam owned by instructorUser
    $this->exam = Exam::create([
        'title' => 'Python Basics',
        'slug' => 'python-basics',
        'short_description' => 'Basics of Python',
        'status' => 'published',
        'pass_mark' => 20,
        'total_marks' => 40,
        'instructor_id' => $this->instructor->id,
        'exam_category_id' => $this->category->id,
    ]);
});

it('downloads sample CSV file successfully', function () {
    $response = $this->actingAs($this->instructorUser)
        ->get(route('exam-questions.import-sample'));

    $response->assertOk();
    $response->assertHeader('Content-Disposition', 'attachment; filename="exam_questions_sample.csv"');
    $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
    
    $content = $response->streamedContent();
    expect($content)->toContain('title,question_type,marks,description,option_1,option_2,option_3,option_4,option_5,correct_options');
});

it('imports valid CSV questions successfully', function () {
    $csvContent = "title,question_type,marks,description,option_1,option_2,option_3,option_4,option_5,correct_options\n"
        . "\"What is 2+2?\",\"multiple_choice\",\"5\",\"Math question\",\"3\",\"4\",\"5\",\"6\",\"\",\"2\"\n"
        . "\"List primary colors.\",\"multiple_select\",\"10\",\"Colors question\",\"Red\",\"Green\",\"Blue\",\"Yellow\",\"\",\"1,3\"\n"
        . "\"Sky is blue.\",\"true_false\",\"5\",\"True/False question\",\"\",\"\",\"\",\"\",\"\",\"1\"\n"
        . "\"Write a short essay on AI.\",\"short_answer\",\"10\",\"Short answer question\",\"\",\"\",\"\",\"\",\"\",\"\"\n";

    $file = UploadedFile::fake()->createWithContent('questions.csv', $csvContent)->mimeType('text/csv');

    $response = $this->actingAs($this->instructorUser)
        ->post(route('exam-questions.import', $this->exam->id), [
            'file' => $file,
        ]);

    $response->assertRedirect();
    $response->assertSessionHas('success');

    // Check database counts
    expect(ExamQuestion::count())->toBe(4);
    expect(ExamQuestionOption::count())->toBe(10); // 4 for MCQ + 4 for MSQ + 2 for True/False

    // Check specific question attributes
    $mcq = ExamQuestion::where('question_type', 'multiple_choice')->first();
    expect($mcq->title)->toBe('What is 2+2?')
        ->and($mcq->marks)->toBe("5.00");

    $correctOption = $mcq->question_options()->where('is_correct', true)->first();
    expect($correctOption->option_text)->toBe('4');
});

it('aborts and rollbacks whole transaction if a row validation fails', function () {
    // Second row is invalid (non-numeric marks)
    $csvContent = "title,question_type,marks,description,option_1,option_2,option_3,option_4,option_5,correct_options\n"
        . "\"What is 2+2?\",\"multiple_choice\",\"5\",\"Math question\",\"3\",\"4\",\"5\",\"6\",\"\",\"2\"\n"
        . "\"Invalid marks question\",\"short_answer\",\"abc\",\"desc\",\"\",\"\",\"\",\"\",\"\",\"\"\n";

    $file = UploadedFile::fake()->createWithContent('questions.csv', $csvContent)->mimeType('text/csv');

    $response = $this->actingAs($this->instructorUser)
        ->post(route('exam-questions.import', $this->exam->id), [
            'file' => $file,
        ]);

    $response->assertRedirect();
    $response->assertSessionHasErrors('import_errors');

    // No questions should be in database (rollback works)
    expect(ExamQuestion::count())->toBe(0);
});

it('validates invalid header templates', function () {
    // Missing 'marks' column
    $csvContent = "title,question_type,description,option_1,option_2,correct_options\n"
        . "\"What is 2+2?\",\"multiple_choice\",\"Math question\",\"3\",\"4\",\"2\"\n";

    $file = UploadedFile::fake()->createWithContent('questions.csv', $csvContent)->mimeType('text/csv');

    $response = $this->actingAs($this->instructorUser)
        ->post(route('exam-questions.import', $this->exam->id), [
            'file' => $file,
        ]);

    $response->assertRedirect();
    $response->assertSessionHasErrors('import_errors');
    
    $errors = session('errors')->get('import_errors');
    expect($errors[0])->toContain('Invalid template');
});

it('denies authorization for other instructors', function () {
    $csvContent = "title,question_type,marks,description,option_1,option_2,option_3,option_4,option_5,correct_options\n"
        . "\"What is 2+2?\",\"multiple_choice\",\"5\",\"Math question\",\"3\",\"4\",\"5\",\"6\",\"\",\"2\"\n";

    $file = UploadedFile::fake()->createWithContent('questions.csv', $csvContent)->mimeType('text/csv');

    $response = $this->actingAs($this->otherInstructorUser)
        ->post(route('exam-questions.import', $this->exam->id), [
            'file' => $file,
        ]);

    $response->assertForbidden();
    expect(ExamQuestion::count())->toBe(0);
});

it('allows admin to import questions to any exam', function () {
    $csvContent = "title,question_type,marks,description,option_1,option_2,option_3,option_4,option_5,correct_options\n"
        . "\"What is 2+2?\",\"multiple_choice\",\"5\",\"Math question\",\"3\",\"4\",\"5\",\"6\",\"\",\"2\"\n";

    $file = UploadedFile::fake()->createWithContent('questions.csv', $csvContent)->mimeType('text/csv');

    $response = $this->actingAs($this->admin)
        ->post(route('exam-questions.import', $this->exam->id), [
            'file' => $file,
        ]);

    $response->assertRedirect();
    $response->assertSessionHas('success');
    expect(ExamQuestion::count())->toBe(1);
});
