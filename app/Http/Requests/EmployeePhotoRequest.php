<?php

namespace App\Http\Requests;

use App\Models\Employee;
use App\Support\EmployeeFiles;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;

/**
 * Upload or replace an employee's photo. Same permission as editing the record: employees.manage, and only an Admin
 * touches an Admin or HR person's record. PNG, JPEG or WebP read from the content (never the name or the browser's
 * MIME type), at most 2 MB, 64x64 to 2000x2000 pixels. The image is stored as it is (not re-encoded) and served with
 * its detected type and nosniff.
 */
class EmployeePhotoRequest extends FormRequest
{
    public const TYPE_MESSAGE = 'The photo must be a PNG, JPG or WebP image.';

    public const SIZE_MESSAGE = 'The photo may not be larger than 2 MB.';

    public const DIMENSIONS_MESSAGE = 'The photo must be between 64×64 and 2000×2000 pixels.';

    public const MISSING_MESSAGE = 'Choose a photo to upload.';

    public function authorize(): bool
    {
        $employee = $this->route('employee');

        return $this->user() !== null && $employee instanceof Employee && EmployeeFiles::mayManage($this->user(), $employee);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'photo' => [
                'bail',
                'required',
                'file',
                'max:'.EmployeeFiles::PHOTO_MAX_KB,
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (! $value instanceof UploadedFile || EmployeeFiles::photoExtension($value) === null) {
                        $fail(self::TYPE_MESSAGE);
                    }
                },
                function (string $attribute, mixed $value, Closure $fail): void {
                    [$width, $height] = EmployeeFiles::imageSize($value) ?? [0, 0];
                    $min = EmployeeFiles::PHOTO_MIN_SIDE;
                    $max = EmployeeFiles::PHOTO_MAX_SIDE;

                    if ($width < $min || $height < $min || $width > $max || $height > $max) {
                        $fail(self::DIMENSIONS_MESSAGE);
                    }
                },
            ],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'photo.required' => self::MISSING_MESSAGE,
            'photo.file' => self::MISSING_MESSAGE,
            'photo.uploaded' => self::SIZE_MESSAGE,
            'photo.max' => self::SIZE_MESSAGE,
        ];
    }
}
