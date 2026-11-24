<?php

use App\Models\Department;
use App\Models\Designation;
use App\Models\Employee;
use App\Models\EmployeeBankAccount;
use App\Models\EmployeeContact;
use App\Models\EmployeeDocument;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('creates the tables and columns', function () {
    expect(Schema::getColumnListing('employees'))->toEqualCanonicalizing([
        'id', 'employee_code', 'device_user_id', 'first_name', 'last_name', 'photo_path', 'email', 'phone', 'dob',
        'gender', 'cnic', 'department_id', 'designation_id', 'manager_id', 'joining_date', 'probation_end_date',
        'employment_type', 'status', 'last_working_day', 'created_at', 'updated_at',
    ])
        ->and(Schema::getColumnListing('employee_contacts'))->toEqualCanonicalizing([
            'id', 'employee_id', 'address', 'emergency_name', 'emergency_phone', 'created_at', 'updated_at',
        ])
        ->and(Schema::getColumnListing('employee_bank_accounts'))->toEqualCanonicalizing([
            'id', 'employee_id', 'bank_name', 'account_title', 'account_number', 'created_at', 'updated_at',
        ])
        ->and(Schema::getColumnListing('employee_documents'))->toEqualCanonicalizing([
            'id', 'employee_id', 'type', 'title', 'file_path', 'original_name', 'mime', 'size_bytes', 'expiry_date',
            'uploaded_by', 'created_at', 'updated_at',
        ])
        ->and(Schema::hasColumn('users', 'employee_id'))->toBeTrue()
        ->and(Schema::hasColumn('departments', 'head_employee_id'))->toBeTrue()
        ->and(Schema::hasColumn('employees', 'shift_id'))->toBeFalse();
});

it('has the factory produce valid, unique records', function () {
    $employees = Employee::factory()->count(5)->create();

    expect($employees->pluck('employee_code')->unique())->toHaveCount(5)
        ->and($employees->pluck('email')->unique())->toHaveCount(5)
        ->and($employees->pluck('cnic')->unique())->toHaveCount(5);

    foreach ($employees as $e) {
        expect($e->cnic)->toMatch('/^\d{13}$/')
            ->and($e->employee_code)->toMatch('/^LD-\d{4,}$/')
            ->and($e->designation->department_id)->toBe($e->department_id)
            ->and(config('hrm.employees.statuses'))->toContain($e->status)
            ->and(config('hrm.employees.employment_types'))->toContain($e->employment_type);
    }
});

it('keeps the designation in the same department when the department is given', function () {
    $department = Department::factory()->create();
    $employee = Employee::factory()->create(['department_id' => $department->id]);

    expect($employee->designation->department_id)->toBe($department->id);
});

it('exposes the enums in config', function () {
    expect(config('hrm.employees.statuses'))->toBe(['active', 'probation', 'inactive', 'resigned', 'terminated'])
        ->and(config('hrm.employees.employment_types'))->toBe(['full_time', 'part_time', 'contract', 'intern'])
        ->and(config('hrm.employees.genders'))->toBe(['male', 'female', 'other'])
        ->and(config('hrm.employees.document_types'))->toBe(['cnic', 'contract', 'offer_letter', 'certificate', 'other']);
});

it('builds the full name and the relations', function () {
    $boss = Employee::factory()->create();
    $e = Employee::factory()->create(['first_name' => 'Ayesha', 'last_name' => 'Khan', 'manager_id' => $boss->id]);
    $contact = EmployeeContact::factory()->create(['employee_id' => $e->id]);
    $bank = EmployeeBankAccount::factory()->create(['employee_id' => $e->id]);
    $doc = EmployeeDocument::factory()->create(['employee_id' => $e->id]);
    $user = User::factory()->create();
    $user->forceFill(['employee_id' => $e->id])->save();

    $e->refresh();

    expect($e->full_name)->toBe('Ayesha Khan')
        ->and($e->department)->toBeInstanceOf(Department::class)
        ->and($e->designation)->toBeInstanceOf(Designation::class)
        ->and($e->manager->is($boss))->toBeTrue()
        ->and($boss->reports->pluck('id')->all())->toBe([$e->id])
        ->and($e->contact->is($contact))->toBeTrue()
        ->and($e->bankAccount->is($bank))->toBeTrue()
        ->and($e->documents->pluck('id')->all())->toBe([$doc->id])
        ->and($e->user->is($user))->toBeTrue()
        ->and($user->refresh()->employee->is($e))->toBeTrue()
        ->and($doc->employee->is($e))->toBeTrue();
});

it('scopes by status and searches name, code and email', function () {
    $a = Employee::factory()->create(['first_name' => 'Ayesha', 'last_name' => 'Khan', 'status' => 'active', 'employee_code' => 'LD-0100', 'email' => 'a.khan@example.test']);
    $b = Employee::factory()->create(['first_name' => 'Bilal', 'last_name' => 'Raza', 'status' => 'resigned', 'employee_code' => 'LD-0200', 'email' => 'bilal@example.test']);
    Employee::factory()->create(['first_name' => '100%', 'last_name' => 'Literal', 'status' => 'active']);

    expect(Employee::status('resigned')->pluck('id')->all())->toBe([$b->id])
        ->and(Employee::search('ayesha')->pluck('id')->all())->toBe([$a->id])
        ->and(Employee::search('AYESHA KHAN')->pluck('id')->all())->toBe([$a->id])
        ->and(Employee::search('LD-0200')->pluck('id')->all())->toBe([$b->id])
        ->and(Employee::search('bilal@')->pluck('id')->all())->toBe([$b->id])
        ->and(Employee::search('100%')->count())->toBe(1)
        ->and(Employee::search('%')->count())->toBe(1)
        ->and(Employee::search('_')->count())->toBe(0);
});

it('refuses duplicate email, code, cnic and device user id, and allows many nulls', function (string $column, string $value) {
    Employee::factory()->create([$column => $value]);

    expect(fn () => Employee::factory()->create([$column => $value]))->toThrow(UniqueConstraintViolationException::class);
})->with([
    'email' => ['email', 'same@example.test'],
    'employee_code' => ['employee_code', 'LD-7777'],
    'cnic' => ['cnic', '4220112345671'],
    'device_user_id' => ['device_user_id', '4711'],
]);

it('allows many employees without a cnic or machine id', function () {
    Employee::factory()->count(3)->create(['cnic' => null, 'device_user_id' => null]);

    expect(Employee::whereNull('cnic')->count())->toBe(3);
});

it('allows only one employee record per user, one contact and one bank account per employee', function () {
    $e = Employee::factory()->create();
    EmployeeContact::factory()->create(['employee_id' => $e->id]);
    EmployeeBankAccount::factory()->create(['employee_id' => $e->id]);

    expect(fn () => EmployeeContact::factory()->create(['employee_id' => $e->id]))->toThrow(UniqueConstraintViolationException::class)
        ->and(fn () => EmployeeBankAccount::factory()->create(['employee_id' => $e->id]))->toThrow(UniqueConstraintViolationException::class);

    $a = User::factory()->create();
    $b = User::factory()->create();
    $a->forceFill(['employee_id' => $e->id])->save();

    expect(fn () => $b->forceFill(['employee_id' => $e->id])->save())->toThrow(UniqueConstraintViolationException::class);
});

it('restricts deleting a department or designation that employees use, in the database itself', function () {
    $e = Employee::factory()->create();

    expect(fn () => DB::table('departments')->where('id', $e->department_id)->delete())->toThrow(QueryException::class)
        ->and(fn () => DB::table('designations')->where('id', $e->designation_id)->delete())->toThrow(QueryException::class);
});

it('sets the manager to null when the manager is deleted, and cascades the satellites', function () {
    $boss = Employee::factory()->create();
    $e = Employee::factory()->create(['manager_id' => $boss->id]);
    EmployeeContact::factory()->create(['employee_id' => $e->id]);
    EmployeeBankAccount::factory()->create(['employee_id' => $e->id]);
    EmployeeDocument::factory()->create(['employee_id' => $e->id]);

    $boss->delete();
    expect($e->refresh()->manager_id)->toBeNull();

    $e->delete();
    expect(EmployeeContact::count())->toBe(0)->and(EmployeeBankAccount::count())->toBe(0)->and(EmployeeDocument::count())->toBe(0);
});

it('clears the user link and the department head when the employee is deleted', function () {
    $e = Employee::factory()->create();
    $user = User::factory()->create();
    $user->forceFill(['employee_id' => $e->id])->save();
    $e->department->forceFill(['head_employee_id' => $e->id])->save();

    $e->delete();

    expect($user->refresh()->employee_id)->toBeNull()
        ->and(Department::first()->head_employee_id)->toBeNull();
});

it('lets a user exist without an employee record', function () {
    $user = User::factory()->create();

    expect($user->employee_id)->toBeNull()->and($user->employee)->toBeNull();
});

it('gives a department its head', function () {
    $e = Employee::factory()->create();
    $e->department->forceFill(['head_employee_id' => $e->id])->save();

    expect($e->department->refresh()->head->is($e))->toBeTrue();
});

it('keeps dates as calendar dates', function () {
    $e = Employee::factory()->create(['joining_date' => '2026-03-01', 'dob' => '1990-12-31']);

    expect($e->refresh()->joining_date->format('Y-m-d'))->toBe('2026-03-01')
        ->and($e->toArray()['joining_date'])->toBe('2026-03-01')
        ->and($e->toArray()['dob'])->toBe('1990-12-31');
});
