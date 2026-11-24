<?php

namespace Database\Factories;

use App\Models\Employee;
use App\Models\EmployeeDocument;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<EmployeeDocument>
 */
class EmployeeDocumentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'employee_id' => Employee::factory(),
            'type' => 'other',
            'title' => 'Document '.fake()->numerify('####'),
            'file_path' => 'employees/0/documents/'.Str::random(40).'.pdf',
            'original_name' => 'scan.pdf',
            'mime' => 'application/pdf',
            'size_bytes' => 12345,
            'expiry_date' => null,
            'uploaded_by' => null,
        ];
    }
}
