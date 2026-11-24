<?php

namespace App\Http\Requests;

use App\Models\Employee;
use App\Support\EmployeeFiles;
use App\Support\Names;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;

/**
 * Add a document to an employee. Same permission as editing the record. The file's content must be the type its
 * extension claims, from an allow-list: PDF, Word (DOC, DOCX) or a PNG, JPEG or WebP image, at most 10 MB. HTML,
 * SVG, scripts and programs are refused whatever their name. Error messages never repeat anything from the file.
 */
class EmployeeDocumentRequest extends FormRequest
{
    public const TYPE_MESSAGE = 'The file must be a PDF, a Word document (DOC or DOCX) or a PNG, JPG or WebP image.';

    public const SIZE_MESSAGE = 'The file may not be larger than 10 MB.';

    public function authorize(): bool
    {
        $employee = $this->route('employee');

        return $this->user() !== null && $employee instanceof Employee && EmployeeFiles::mayManage($this->user(), $employee);
    }

    protected function prepareForValidation(): void
    {
        $title = $this->input('title');

        if (is_string($title)) {
            // No control characters, whitespace collapsed (tabs and new lines included).
            $this->merge(['title' => Names::normalise((string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/u', '', $title))]);
        }

        if ($this->input('expiry_date') === '') {
            $this->merge(['expiry_date' => null]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'type' => ['required', 'string', Rule::in(config('hrm.employees.document_types'))],
            'title' => ['required', 'string', 'max:150'],
            'file' => [
                'bail',
                'required',
                'file',
                'max:'.EmployeeFiles::DOCUMENT_MAX_KB,
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (! $value instanceof UploadedFile || EmployeeFiles::documentExtension($value) === null) {
                        $fail(self::TYPE_MESSAGE);
                    }
                },
            ],
            'expiry_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:2000-01-01', 'before:2100-01-01'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'type.required' => 'Choose the type of document.',
            'type.in' => 'Choose the type of document.',
            'title.required' => 'Enter a title.',
            'title.max' => 'The title may not be longer than 150 characters.',
            'file.required' => 'Choose a file to upload.',
            'file.file' => 'Choose a file to upload.',
            'file.uploaded' => self::SIZE_MESSAGE,
            'file.max' => self::SIZE_MESSAGE,
            'expiry_date.date_format' => 'Enter the expiry date as a date.',
            'expiry_date.after_or_equal' => 'The expiry date cannot be before 1 January 2000.',
            'expiry_date.before' => 'The expiry date must be before the year 2100.',
        ];
    }
}
