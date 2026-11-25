<?php

use App\Models\Department;
use App\Models\Designation;
use App\Models\Employee;
use App\Models\EmployeeBankAccount;
use App\Models\EmployeeContact;
use App\Models\EmployeeDocument;
use App\Models\User;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

// Another person's distinctive values. None of them may ever appear in someone else's My Profile.
const MP_OTHER_FIRST = 'Zanzibarina';
const MP_OTHER_LAST = 'Otherperson';
const MP_OTHER_EMAIL = 'zanzibarina.other@example.test';
const MP_OTHER_CNIC = '4220155554443';
const MP_OTHER_DOB = '1979-02-17';
const MP_OTHER_ADDRESS = 'Quokka Lane 9981, Karachi';
const MP_OTHER_BANK = 'Otherworld Bank';
const MP_OTHER_ACCOUNT = 'PK00OTHR1234567890123456';
const MP_OTHER_DOC = 'Other Person Passport';

// The viewer's own distinctive values.
const MP_OWN_CNIC = '3520212345671';
const MP_OWN_DOB = '1992-08-09';
const MP_OWN_ADDRESS = 'Own House 12, Lahore';
const MP_OWN_BANK = 'Own Savings Bank';
const MP_OWN_ACCOUNT = 'PK11OWNB0000111122223333';
const MP_OWN_DOC = 'My Signed Contract';
const MP_PDF = "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n";

const MP_ALL_TABS = ['basic', 'employment', 'contact', 'banking', 'documents', 'attendance'];
const MP_ALL_KEYS = ['id', 'code', 'name', 'photo_url', 'basic', 'identity', 'employment', 'contact', 'banking', 'documents', 'attendance'];

beforeEach(function () {
    Notification::fake();
    Storage::fake('local');
});

function mpDept(): array
{
    $dept = Department::where('name', 'Operations')->first() ?? Department::factory()->create(['name' => 'Operations']);
    $desig = Designation::where('department_id', $dept->id)->first()
        ?? Designation::factory()->create(['department_id' => $dept->id, 'name' => 'Officer']);

    return ['department_id' => $dept->id, 'designation_id' => $desig->id];
}

/** A document stored on the (fake) private disk, as the upload would. */
function mpDocument(Employee $employee, string $title): EmployeeDocument
{
    $path = "employees/{$employee->id}/documents/".Str::random(40).'.pdf';
    Storage::disk('local')->put($path, MP_PDF);

    return EmployeeDocument::factory()->create([
        'employee_id' => $employee->id,
        'type' => 'contract',
        'title' => $title,
        'file_path' => $path,
        'original_name' => 'contract.pdf',
        'mime' => 'application/pdf',
        'size_bytes' => strlen(MP_PDF),
    ]);
}

/** The viewer's own record, every group filled. */
function mpOwn(User $user, array $attrs = []): Employee
{
    $employee = employeeFor($user, array_merge(mpDept(), [
        'cnic' => MP_OWN_CNIC,
        'dob' => MP_OWN_DOB,
        'device_user_id' => 'OWN-MACHINE-1',
        'joining_date' => '2023-01-02',
    ], $attrs));

    EmployeeContact::factory()->create(['employee_id' => $employee->id, 'address' => MP_OWN_ADDRESS]);
    EmployeeBankAccount::factory()->create(['employee_id' => $employee->id, 'bank_name' => MP_OWN_BANK, 'account_number' => MP_OWN_ACCOUNT]);
    mpDocument($employee, MP_OWN_DOC);

    return $employee;
}

/** Somebody else, with distinctive values everywhere. */
function mpOther(array $attrs = []): Employee
{
    $employee = Employee::factory()->create(array_merge(mpDept(), [
        'first_name' => MP_OTHER_FIRST,
        'last_name' => MP_OTHER_LAST,
        'email' => MP_OTHER_EMAIL,
        'cnic' => MP_OTHER_CNIC,
        'dob' => MP_OTHER_DOB,
        'device_user_id' => 'OTHER-MACHINE-9',
    ], $attrs));

    EmployeeContact::factory()->create(['employee_id' => $employee->id, 'address' => MP_OTHER_ADDRESS]);
    EmployeeBankAccount::factory()->create(['employee_id' => $employee->id, 'bank_name' => MP_OTHER_BANK, 'account_number' => MP_OTHER_ACCOUNT]);
    mpDocument($employee, MP_OTHER_DOC);

    return $employee;
}

function mpProps($response): array
{
    return $response->viewData('page')['props'];
}

/** The page's own props (shared props such as auth and app left out). */
function mpPageProps($response): array
{
    $props = mpProps($response);

    return array_intersect_key($props, array_flip(['employee', 'tabs', 'showLinkHint']));
}

// ---------------------------------------------------------------- route

it('is GET /my/profile, named my.profile, and sends guests to sign in', function () {
    expect(route('my.profile', [], false))->toBe('/my/profile');

    $this->get('/my/profile')->assertRedirect('/login');
});

// ---------------------------------------------------------------- own record, every role

it('shows every role only their own record with all six groups, read-only', function (string $role) {
    $user = userWithRole($role);
    $own = mpOwn($user);
    $other = mpOther(['manager_id' => $own->id]); // even a direct report must not leak into My Profile

    $response = $this->actingAs($user)->get('/my/profile')->assertOk()
        ->assertInertia(fn ($p) => $p->component('my/profile'));
    $props = mpProps($response);

    expect($props['tabs'])->toBe(MP_ALL_TABS)
        ->and(array_keys($props['employee']))->toBe(MP_ALL_KEYS)
        ->and($props['employee']['id'])->toBe($own->id)
        ->and($props['employee']['identity'])->toBe(['cnic' => '35202-1234567-1', 'dob' => MP_OWN_DOB])
        ->and($props['employee']['contact']['address'])->toBe(MP_OWN_ADDRESS)
        ->and($props['employee']['banking']['account_number'])->toBe(MP_OWN_ACCOUNT)
        ->and(array_column($props['employee']['documents']['items'], 'title'))->toBe([MP_OWN_DOC])
        // Read-only: no edit, status or delete rights anywhere in the page props.
        ->and($props)->not->toHaveKey('can')
        ->and($props)->not->toHaveKey('statusOptions');

    // The other person: nothing of theirs except, for a manager, the direct-report line (name, code, designation).
    $json = json_encode(mpPageProps($response));
    foreach ([MP_OTHER_EMAIL, MP_OTHER_CNIC, '42201-5555444-3', MP_OTHER_DOB, MP_OTHER_ADDRESS, MP_OTHER_BANK, MP_OTHER_ACCOUNT, MP_OTHER_DOC, 'OTHER-MACHINE-9'] as $secret) {
        expect($json)->not->toContain($secret);
    }

    if ($role === 'employee') {
        expect($json)->not->toContain(MP_OTHER_FIRST);
    }
})->with(['admin', 'hr', 'manager', 'employee']);

it('lists only the direct reports a manager may see on their own My Profile, with no restricted fields', function () {
    $user = userWithRole('manager');
    $own = mpOwn($user);
    $report = mpOther(['manager_id' => $own->id]);

    $props = mpProps($this->actingAs($user)->get('/my/profile')->assertOk());

    expect($props['employee']['employment']['reports'])->toBe([[
        'id' => $report->id,
        'name' => MP_OTHER_FIRST.' '.MP_OTHER_LAST,
        'code' => $report->employee_code,
        'designation' => 'Officer',
    ]]);
});

it('omits machine ID, role and login from the Employment group unless the viewer is Admin or HR', function (string $role, bool $device, bool $account) {
    $user = userWithRole($role);
    mpOwn($user);

    $employment = mpProps($this->actingAs($user)->get('/my/profile')->assertOk())['employee']['employment'];

    expect(array_key_exists('device_user_id', $employment))->toBe($device)
        ->and(array_key_exists('role', $employment))->toBe($account)
        ->and(array_key_exists('login', $employment))->toBe($account);
})->with([
    ['admin', true, true],
    ['hr', true, true],
    ['manager', false, false],
    ['employee', false, false],
]);

it('gives the own manager a name without a link for a plain employee', function () {
    $boss = mpOther();
    $user = userWithRole('employee');
    mpOwn($user, ['manager_id' => $boss->id]);

    $employment = mpProps($this->actingAs($user)->get('/my/profile')->assertOk())['employee']['employment'];

    expect($employment['manager'])->toBe(['id' => null, 'name' => MP_OTHER_FIRST.' '.MP_OTHER_LAST]);
});

it('shows the own photo through the authorised photo route', function () {
    $user = userWithRole('employee');
    $own = mpOwn($user);
    $path = "employees/{$own->id}/photo/".Str::random(40).'.png';
    Storage::disk('local')->put($path, 'not checked here');
    $own->forceFill(['photo_path' => $path])->saveQuietly();

    $props = mpProps($this->actingAs($user)->get('/my/profile')->assertOk());

    expect($props['employee']['photo_url'])->toStartWith("/employees/{$own->id}/photo?v=");
});

// ---------------------------------------------------------------- unlinked account

it('shows an unlinked account an empty state, with the link hint only for people who manage employees', function (string $role, bool $hint) {
    $user = userWithRole($role);
    mpOther();

    $response = $this->actingAs($user)->get('/my/profile')->assertOk()
        ->assertInertia(fn ($p) => $p->component('my/profile'));
    $props = mpProps($response);

    expect($props['employee'])->toBeNull()
        ->and($props['tabs'])->toBe([])
        ->and($props['showLinkHint'])->toBe($hint)
        ->and(json_encode(mpPageProps($response)))->not->toContain(MP_OTHER_FIRST);
})->with([
    ['admin', true],
    ['hr', true],
    ['manager', false],
    ['employee', false],
]);

// ---------------------------------------------------------------- documents and the profile redirect

it('lets a plain employee download their own document but not another person\'s', function () {
    $user = userWithRole('employee');
    $own = mpOwn($user);
    $other = mpOther();
    $ownDoc = $own->documents()->first();
    $otherDoc = $other->documents()->first();

    $props = mpProps($this->actingAs($user)->get('/my/profile')->assertOk());
    $url = $props['employee']['documents']['items'][0]['download_url'];

    $this->actingAs($user)->get($url)->assertOk()->assertDownload();
    expect($url)->toContain("/employees/{$own->id}/documents/{$ownDoc->id}");
    $this->actingAs($user)->get("/employees/{$other->id}/documents/{$otherDoc->id}")->assertForbidden();
    // Someone else's document id under the own employee id is not found, not served.
    $this->actingAs($user)->get("/employees/{$own->id}/documents/{$otherDoc->id}")->assertNotFound();
});

it('sends a plain employee from their own profile URL to My Profile, which opens', function () {
    $user = userWithRole('employee');
    $own = mpOwn($user);

    $this->actingAs($user)->followingRedirects()->get("/employees/{$own->id}")->assertOk()
        ->assertInertia(fn ($p) => $p->component('my/profile')->where('employee.id', $own->id));
});

it('keeps Admin, HR and Manager on the normal profile page for their own record', function (string $role) {
    $user = userWithRole($role);
    $own = mpOwn($user);

    $this->actingAs($user)->get("/employees/{$own->id}")->assertOk()
        ->assertInertia(fn ($p) => $p->component('employees/show'));
})->with(['admin', 'hr', 'manager']);
