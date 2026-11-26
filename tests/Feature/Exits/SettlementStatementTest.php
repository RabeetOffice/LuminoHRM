<?php

use App\Models\Employee;
use App\Models\FinalSettlement;
use App\Support\Exits\SettlementStatementPdf;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;

/*
| The final settlement statement: draft refused, content from the stored figures only, identical after later changes,
| "Owed by employee" wording, access (403 for Manager and another employee, own copy for the leaver).
| "Now" is Wednesday 2026-12-16 14:00 in Karachi.
*/

beforeEach(function () {
    $this->withoutVite();
    $this->travelTo(CarbonImmutable::parse('2026-12-16 14:00:00', 'Asia/Karachi'));
    Storage::fake('local');
});

/** An approved settlement of an exit ending 2026-12-10 with a full stored breakdown (figures in paisa). */
function ssSettlement(array $attrs = [], array $settlement = []): FinalSettlement
{
    $user = userWithRole('employee');
    $employee = employeeFor($user, ['joining_date' => '2024-03-01', 'employee_code' => 'LD-0042', 'first_name' => 'Sara', 'last_name' => 'Ahmed']);
    $exit = approvedExit($employee, '2026-12-10');

    return settlementRow($exit, [
        'status' => 'approved',
        'approved_at' => '2026-12-11 10:00:00',
        'net' => 5_000_000,
        'summary' => ['company' => ['name' => 'Lumino Ltd', 'address' => "1 Main Road\nKarachi", 'footer' => 'Computer generated.']],
        'breakdown' => [
            'version' => 1,
            'employee' => ['id' => $employee->id, 'code' => 'LD-0042', 'name' => 'Sara Ahmed', 'department' => 'Ops', 'designation' => 'Analyst', 'joining_date' => '2024-03-01'],
            'period' => ['month' => '2026-12', 'from' => '2026-12-01', 'to' => '2026-12-10', 'days_in_month' => 31, 'days_paid' => 10],
            'earnings' => [['label' => 'Basic pay', 'amount' => 2_000_000], ['label' => 'Leave encashment', 'amount' => 3_000_000]],
            'deductions' => [['label' => 'Late deduction', 'amount' => 100_000]],
            'tax' => ['mode' => 'percentage', 'base' => 'gross_taxable', 'rate_bp' => 500, 'taxable_pay' => 5_000_000, 'gross_taxable_pay' => 5_000_000, 'amount' => 250_000, 'overridden' => false],
            'attendance' => ['present' => 8, 'late' => 1],
            'totals' => ['gross' => 5_000_000, 'attendance_deduction' => 100_000, 'fixed_deductions' => 0, 'other_deductions' => 0, 'loan_deduction' => 0, 'tax' => 250_000, 'net' => 5_000_000],
            'settlement' => [
                'last_working_day' => '2026-12-10', 'exit_type' => 'resignation',
                'encashment' => ['enabled' => true, 'type_name' => 'Annual', 'days' => 6, 'amount' => 3_000_000],
                'lines' => [], 'loans' => [['loan_id' => 1, 'type' => 'Advance', 'remaining' => 400_000, 'recovered' => 400_000]],
                'loan_shortfall' => 0, 'owed_by_employee' => 0, ...$settlement,
            ],
        ],
        ...$attrs,
    ]);
}

it('refuses a draft', function () {
    $s = ssSettlement(['status' => 'draft']);

    expect(fn () => SettlementStatementPdf::content($s))->toThrow(LogicException::class, 'The statement is available once the settlement is approved.')
        ->and(fn () => SettlementStatementPdf::render($s))->toThrow(LogicException::class);
});

it('builds its content from the stored figures and names the file', function () {
    $s = ssSettlement();
    $c = SettlementStatementPdf::content($s);

    expect($c['title'])->toBe('Final settlement — December 2026')
        ->and($c['company']['name'])->toBe('Lumino Ltd')
        ->and($c['company']['address'])->toBe('1 Main Road, Karachi')
        ->and($c['earnings'])->toBe([['Basic pay', 'Rs 20,000'], ['Leave encashment', 'Rs 30,000']])
        ->and($c['gross'])->toBe('Rs 50,000')
        ->and($c['net_label'])->toBe('Net pay')
        ->and($c['net'])->toBe('Rs 50,000')
        ->and($c['encashment'])->toBe(['Annual', '6 days', 'Rs 30,000'])
        ->and($c['loans'])->toBe([['Advance', 'Outstanding Rs 4,000', 'Rs 4,000']])
        ->and(SettlementStatementPdf::filename($s))->toBe('Final settlement - LD-0042 - December 2026.pdf')
        ->and(SettlementStatementPdf::pathFor($s))->toBe("settlements/2026-12/LD-0042-{$s->id}.pdf");
});

it('says owed by employee when the net is negative', function () {
    $s = ssSettlement(['net' => -1_250_000], ['owed_by_employee' => 1_250_000, 'loan_shortfall' => 300_000]);
    $s->breakdown = [...$s->breakdown, 'totals' => [...$s->breakdown['totals'], 'net' => -1_250_000]];
    $s->save();

    $c = SettlementStatementPdf::content($s->refresh());

    expect($c['owed'])->toBeTrue()
        ->and($c['net_label'])->toBe('Owed by employee')
        ->and($c['net'])->toBe('Rs 12,500')
        ->and($c['loan_shortfall'])->toBe('Rs 3,000');
});

it('renders the same content after the profile or settings change', function () {
    $s = ssSettlement();
    $before = SettlementStatementPdf::content($s);

    Employee::query()->whereKey($s->employee_id)->update(['first_name' => 'Changed', 'designation_id' => null]);
    Settings::set('company', ['name' => 'Another Name']);

    expect(SettlementStatementPdf::content($s->refresh()))->toBe($before);
    expect(SettlementStatementPdf::render($s))->toStartWith('%PDF');
});

it('stores the file once and records its path, and writes it again when missing', function () {
    $s = ssSettlement();

    $path = SettlementStatementPdf::pathOrRender($s);

    expect(Storage::disk('local')->exists($path))->toBeTrue()
        ->and($s->refresh()->statement_path)->toBe($path);

    Storage::disk('local')->delete($path);

    expect(SettlementStatementPdf::pathOrRender($s))->toBe($path)
        ->and(Storage::disk('local')->exists($path))->toBeTrue();
});

it('serves the statement to Admin and HR and refuses a draft', function () {
    $s = ssSettlement();

    $this->actingAs(userWithRole('hr'))->get(route('exit.settlements.statement', $s))
        ->assertOk()->assertHeader('Content-Type', 'application/pdf');

    $draft = ssSettlement(['status' => 'draft']);
    $this->actingAs(userWithRole('admin'))->get(route('exit.settlements.statement', $draft))
        ->assertNotFound();
});

it('refuses a Manager and another employee on the staff route', function () {
    $s = ssSettlement();

    $this->actingAs(userWithRole('manager'))->get(route('exit.settlements.statement', $s))->assertForbidden();
    $this->actingAs(userWithRole('employee'))->get(route('exit.settlements.statement', $s))->assertForbidden();
});

it('gives the leaver their own statement only and only once approved', function () {
    $s = ssSettlement();
    $owner = Employee::query()->find($s->employee_id)->user;

    $this->actingAs($owner)->get(route('my.resignation.statement', $s->resignation_id))->assertOk();

    $other = userWithRole('employee');
    employeeFor($other);
    $this->actingAs($other)->get(route('my.resignation.statement', $s->resignation_id))->assertForbidden();

    $s->forceFill(['status' => 'draft'])->save();
    $this->actingAs($owner)->get(route('my.resignation.statement', $s->resignation_id))->assertNotFound();
});
