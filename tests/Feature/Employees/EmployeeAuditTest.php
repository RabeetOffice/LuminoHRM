<?php

use App\Models\Employee;
use App\Models\EmployeeBankAccount;
use App\Models\EmployeeContact;
use App\Models\EmployeeDocument;
use App\Support\Audit\Audit;
use Spatie\Activitylog\Models\Activity;

it('lists employees as an audit area', function () {
    expect(Audit::AREAS)->toHaveKey('employees');
});

it('records an employee creation in the employees area without cnic or date of birth values', function () {
    $e = Employee::factory()->create(['cnic' => '4220112345671', 'dob' => '1990-05-17', 'first_name' => 'Ayesha', 'last_name' => 'Khan']);

    $entry = Activity::where('log_name', 'employees')->where('subject_id', $e->id)->latest('id')->first();

    expect($entry)->not->toBeNull()
        ->and($entry->description)->toBe('Employee created')
        ->and($entry->properties['attributes']['cnic'])->toBe('[changed]')
        ->and($entry->properties['attributes']['dob'])->toBe('[changed]')
        ->and($entry->properties['attributes']['first_name'])->toBe('Ayesha')
        ->and($entry->properties['subject_label'])->toBe('Ayesha Khan')
        ->and(json_encode($entry->properties))->not->toContain('4220112345671')->not->toContain('1990-05-17');
});

it('records changed fields with old and new, and masks cnic and dob on update', function () {
    $e = Employee::factory()->create(['cnic' => '4220112345671', 'dob' => '1990-05-17', 'status' => 'active']);

    $e->update(['status' => 'resigned', 'cnic' => '4220199999999', 'dob' => '1991-01-01']);

    $entry = Activity::where('log_name', 'employees')->where('subject_id', $e->id)->latest('id')->first();

    expect($entry->properties['old']['status'])->toBe('active')
        ->and($entry->properties['attributes']['status'])->toBe('resigned')
        ->and($entry->properties['old']['cnic'])->toBe('[changed]')
        ->and($entry->properties['attributes']['cnic'])->toBe('[changed]')
        ->and($entry->properties['attributes']['dob'])->toBe('[changed]');

    $json = json_encode($entry->properties);
    foreach (['4220112345671', '4220199999999', '1990-05-17', '1991-01-01'] as $secret) {
        expect($json)->not->toContain($secret);
    }
});

it('logs only allow-listed columns', function () {
    $e = Employee::factory()->create();
    $e->update(['photo_path' => 'employees/1/photo/x.png']);

    $entry = Activity::where('log_name', 'employees')->where('subject_id', $e->id)->latest('id')->first();

    expect($entry->description)->toBe('Employee created');
    expect(json_encode(Activity::where('log_name', 'employees')->get()->pluck('properties')))->not->toContain('employees/1/photo');
});

it('masks the bank account number but logs the bank name', function () {
    $e = Employee::factory()->create();
    $bank = EmployeeBankAccount::factory()->create(['employee_id' => $e->id, 'bank_name' => 'Meezan', 'account_number' => '0123456789012345']);
    $bank->update(['account_number' => '9999888877776666']);

    $entries = Activity::where('log_name', 'employees')->where('subject_type', $bank->getMorphClass())->get();

    expect($entries)->toHaveCount(2);
    $json = json_encode($entries->pluck('properties'));
    expect($json)->toContain('Meezan')->toContain('[changed]')
        ->not->toContain('0123456789012345')->not->toContain('9999888877776666');
});

it('logs contact and document changes in the employees area without file paths', function () {
    $e = Employee::factory()->create();
    EmployeeContact::factory()->create(['employee_id' => $e->id, 'emergency_name' => 'Sara']);
    $doc = EmployeeDocument::factory()->create(['employee_id' => $e->id, 'title' => 'Offer letter', 'file_path' => 'employees/9/documents/secret-name.pdf']);

    expect(Activity::where('log_name', 'employees')->where('subject_type', (new EmployeeContact)->getMorphClass())->count())->toBe(1)
        ->and(Activity::where('log_name', 'employees')->where('subject_type', $doc->getMorphClass())->count())->toBe(1);

    expect(json_encode(Activity::where('log_name', 'employees')->get()->pluck('properties')))->not->toContain('secret-name');
});
