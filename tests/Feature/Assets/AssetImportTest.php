<?php

use App\Actions\Assets\AssignAsset;
use App\Actions\Assets\SaveAsset;
use App\Http\Controllers\Assets\AssetImportController;
use App\Http\Controllers\EmployeeImportController;
use App\Imports\AssetRegisterImport;
use App\Models\Asset;
use App\Models\AssetAssignment;
use App\Models\Employee;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/*
 * Assets > Import under /assets/import: template, upload + preview with per-row errors, run (all or nothing, one audit
 * entry per run plus the actions' own), idempotent re-import. Today is 2026-11-26.
 */

require_once __DIR__.'/Support/assignment-helpers.php';
require_once __DIR__.'/Support/import-helpers.php';

beforeEach(function () {
    $this->withoutVite();
    $this->travelTo(CarbonImmutable::parse('2026-11-26 14:00:00', 'Asia/Karachi'));
    Storage::fake('local');
    $this->excelTemp = sys_get_temp_dir().'/lumino-excel-'.bin2hex(random_bytes(6));
    config(['excel.temporary_files.local_path' => $this->excelTemp]);
});

afterEach(function () {
    File::deleteDirectory($this->excelTemp);
});

function ariSara(): Employee
{
    return Employee::query()->where('employee_code', 'LD-0101')->first() ?? aaPerson(['employee_code' => 'LD-0101']);
}

/** A valid new-asset row, with columns overridden by header name. */
function ariRow(array $override = []): array
{
    $row = array_combine(ARI_HEADERS, [null, 'Dell Latitude 5440', 'Laptop', 'SN-1', '2026-01-15', 185000, 'Good', null, null, null]);

    return array_values(array_merge($row, $override));
}

function ariImports(): int
{
    return Activity::query()->where('description', 'Assets imported')->count();
}

// --- access ----------------------------------------------------------------------------------------------------

test('only HR and Admin may use the import', function (string $role, int $status) {
    $user = userWithRole($role);

    $this->actingAs($user)->get('/assets/import')->assertStatus($status);
    $this->actingAs($user)->get('/assets/import/template')->assertStatus($status);
    $this->actingAs($user)->post('/assets/import/preview', [])->assertStatus($status === 200 ? 302 : 403);
    $this->actingAs($user)->post('/assets/import/run', ['token' => 'x'])->assertStatus($status === 200 ? 302 : 403);
})->with([['admin', 200], ['hr', 200], ['manager', 403], ['employee', 403]]);

it('puts can:assets.assign on every import route', function () {
    foreach (['assets.import', 'assets.import.template', 'assets.import.preview', 'assets.import.run'] as $name) {
        expect(app('router')->getRoutes()->getByName($name)?->gatherMiddleware())->toContain('can:assets.assign');
    }
});

it('redirects guests to sign in', function () {
    $this->get('/assets/import')->assertRedirect('/login');
});

it('renders the upload step with the limits', function () {
    $this->actingAs(userWithRole('hr'))->get('/assets/import')->assertInertia(fn (Assert $page) => $page
        ->component('assets/import')
        ->where('preview', null)
        ->where('expired', false)
        ->where('limits', ['rows' => 2000, 'megabytes' => 5]));
});

it('downloads the template with its headers in order, a help sheet, text columns and a number Cost column', function () {
    $base = $this->actingAs(userWithRole('hr'))->get('/assets/import/template')->assertOk()->baseResponse;
    expect($base)->toBeInstanceOf(BinaryFileResponse::class);

    $book = IOFactory::createReader('Xlsx')->load($base->getFile()->getPathname());
    $help = collect($book->getSheet(1)->toArray())->flatten()->filter()->implode(' ');

    expect($book->getSheet(0)->rangeToArray('A1:J1')[0])->toBe(ARI_HEADERS)
        ->and(array_keys(AssetRegisterImport::columns()))->toBe(ARI_HEADERS)
        ->and($book->getSheet(1)->getTitle())->toBe('Help')
        ->and($book->getSheet(0)->getStyle('E2')->getNumberFormat()->getFormatCode())->toBe('@')
        ->and($book->getSheet(0)->getStyle('F2')->getNumberFormat()->getFormatCode())->toBe('0')
        ->and($help)->toContain('Laptop')->toContain('In Repair');
});

it('shows an expired preview for a token that is not the user\'s', function () {
    $hr = userWithRole('hr');
    $preview = ariPreview($hr, ariXlsx([ariRow()]));

    $this->actingAs(userWithRole('admin'))->get('/assets/import?token='.$preview['token'])
        ->assertInertia(fn (Assert $page) => $page->where('preview', null)->where('expired', true));
    ariRun(userWithRole('admin'), $preview['token'])->assertSessionHas('error', EmployeeImportController::REFUSED);
    expect(Asset::query()->count())->toBe(0);
});

// --- happy path -------------------------------------------------------------------------------------------------

it('adds new assets with codes in file order, hands one over, and audits the run with counts only', function () {
    $hr = userWithRole('hr');
    $sara = ariSara();

    $preview = ariPreview($hr, ariXlsx([
        ariRow(['Name' => 'First', 'Serial' => 'S-1']),
        ariRow(['Name' => 'Second', 'Serial' => 'S-2', 'Category' => 'phone', 'Cost' => null, 'Purchase Date' => null, 'Condition' => 'new']),
        ariRow(['Name' => 'Third', 'Serial' => null, 'Status' => 'Assigned', 'Holder Employee Code' => 'ld-0101', 'Assigned Date' => '2026-11-20']),
    ]));

    expect($preview['totals'])->toBe(['rows' => 3, 'ready' => 3, 'errors' => 0, 'unchanged' => 0])
        ->and($preview['rows'][0]['action'])->toBe('New asset')
        ->and($preview['rows'][0]['cost'])->toBe('Rs 185,000')
        ->and($preview['rows'][2]['holder'])->toBe('Sara Ahmed');

    ariRun($hr, $preview['token'])->assertRedirect('/assets')->assertSessionHas('success', 'Imported 3 rows: 3 added, 0 updated, 0 already up to date.');

    $assets = Asset::query()->orderBy('id')->get();
    expect($assets->pluck('asset_code')->all())->toBe(['AST-0001', 'AST-0002', 'AST-0003'])
        ->and($assets->pluck('name')->all())->toBe(['First', 'Second', 'Third'])
        ->and($assets[0]->cost)->toBe(18500000)
        ->and($assets[0]->purchase_date->format('Y-m-d'))->toBe('2026-01-15')
        ->and($assets[1]->category->name)->toBe('Phone')
        ->and($assets[1]->condition)->toBe('new')
        ->and($assets[1]->cost)->toBeNull()
        ->and($assets[2]->status)->toBe('assigned');

    $open = AssetAssignment::query()->where('asset_id', $assets[2]->id)->sole();
    expect($open->employee_id)->toBe($sara->id)
        ->and($open->assigned_on->format('Y-m-d'))->toBe('2026-11-20')
        ->and($open->returned_on)->toBeNull();
    assertAssetInvariant();

    $entry = Activity::query()->where('description', 'Assets imported')->sole();
    expect($entry->log_name)->toBe('assets')
        ->and($entry->subject_id)->toBeNull()
        ->and($entry->causer_id)->toBe($hr->id)
        ->and(Arr::except($entry->properties->toArray(), ['ip', 'user_agent']))->toBe(['rows' => 3, 'created' => 3, 'updated' => 0, 'unchanged' => 0, 'assigned' => 1])
        ->and(Activity::query()->where('description', 'Asset created')->count())->toBe(3)
        ->and(Activity::query()->where('description', 'Asset assigned')->count())->toBe(1);
});

it('updates the asset a code names, and the asset a serial names (ignoring case), keeping blank fields and setting the serial as typed', function () {
    $hr = userWithRole('hr');
    $byCode = assetRow(['asset_code' => 'AST-0001', 'name' => 'Old', 'serial_number' => 'AAA-1', 'cost' => 5000000, 'condition' => 'good']);
    $bySerial = assetRow(['asset_code' => 'AST-0002', 'name' => 'Phone', 'serial_number' => 'BBB-2', 'cost' => 100000, 'condition' => 'fair', 'category' => 'Phone']);

    $preview = ariPreview($hr, ariXlsx([
        ['AST-0001', 'New name', 'Laptop', null, null, null, null, null, null, null],
        [null, 'Phone', 'Phone', 'bbb-2', null, 2000, 'Poor', null, null, null],
    ]));

    expect($preview['rows'][0]['action'])->toBe('Updates the asset')
        ->and($preview['rows'][1]['code'])->toBe('AST-0002');

    ariRun($hr, $preview['token'])->assertSessionHas('success', 'Imported 2 rows: 0 added, 2 updated, 0 already up to date.');

    expect($byCode->fresh()->name)->toBe('New name')
        ->and($byCode->fresh()->serial_number)->toBe('AAA-1')
        ->and($byCode->fresh()->cost)->toBe(5000000)
        ->and($byCode->fresh()->condition)->toBe('good')
        ->and($bySerial->fresh()->cost)->toBe(200000)
        ->and($bySerial->fresh()->condition)->toBe('poor')
        ->and($bySerial->fresh()->serial_number)->toBe('bbb-2')
        ->and(Asset::query()->count())->toBe(2);
});

it('changes nothing when the same file is imported again', function () {
    $hr = userWithRole('hr');
    ariSara();
    $file = fn () => ariXlsx([
        ariRow(['Serial' => 'S-1']),
        ariRow(['Name' => 'Phone', 'Serial' => 'S-2', 'Category' => 'Phone', 'Holder Employee Code' => 'LD-0101', 'Assigned Date' => '2026-11-20']),
    ]);

    ariRun($hr, ariPreview($hr, $file())['token'])->assertSessionHas('success');
    $before = Activity::query()->count();
    $stamps = Asset::query()->orderBy('id')->pluck('updated_at')->map(fn ($t) => (string) $t)->all();

    // Without codes the rows are matched by serial.
    $again = ariPreview($hr, $file());
    expect($again['totals'])->toBe(['rows' => 2, 'ready' => 2, 'errors' => 0, 'unchanged' => 2])
        ->and(array_column($again['rows'], 'action'))->toBe(['No change', 'No change']);

    $this->travel(5)->minutes();
    ariRun($hr, $again['token'])->assertSessionHas('success', 'Imported 2 rows: 0 added, 0 updated, 2 already up to date.');

    expect(Asset::query()->count())->toBe(2)
        ->and(AssetAssignment::query()->count())->toBe(1)
        ->and(Asset::query()->orderBy('id')->pluck('updated_at')->map(fn ($t) => (string) $t)->all())->toBe($stamps)
        // Only the run's own entry was written.
        ->and(Activity::query()->count())->toBe($before + 1)
        ->and(Arr::except(Activity::query()->latest('id')->first()->properties->toArray(), ['ip', 'user_agent']))->toBe(['rows' => 2, 'created' => 0, 'updated' => 0, 'unchanged' => 2, 'assigned' => 0]);
});

it('works without the Asset Code column and ignores unknown columns with a notice', function () {
    $hr = userWithRole('hr');
    $headers = [...array_slice(ARI_HEADERS, 1), 'Location'];
    $row = [...array_slice(ariRow(), 1), 'Shelf 3'];

    $preview = ariPreview($hr, ariXlsx([$row], $headers));

    expect($preview['notices'])->toBe(['These columns are not part of the template and were ignored: Location.'])
        ->and($preview['totals']['ready'])->toBe(1);
});

it('refuses a file without a required column', function () {
    $hr = userWithRole('hr');

    $this->actingAs($hr)->post('/assets/import/preview', ['file' => ariXlsx([['X']], ['Name'])])
        ->assertSessionHasErrors(['file' => 'The file is missing required columns: Category, Serial, Purchase Date, Cost, Condition, Status, Holder Employee Code, Assigned Date. Use the header row from the template.']);
});

it('warns that a different assigned date is ignored when the holder is the same', function () {
    $hr = userWithRole('hr');
    $sara = ariSara();
    assignedAsset($sara, ['asset_code' => 'AST-0001', 'serial_number' => 'S-1', 'name' => 'Dell Latitude 5440', 'condition' => 'good', 'assigned_on' => '2026-11-10']);

    $preview = ariPreview($hr, ariXlsx([['AST-0001', 'Dell Latitude 5440', 'Laptop', null, null, null, null, 'Assigned', 'LD-0101', '2026-11-20']]));

    expect($preview['rows'][0]['errors'])->toBe([])
        ->and($preview['rows'][0]['warnings'])->toBe(['Assigned date ignored: held since 2026-11-10.'])
        ->and($preview['rows'][0]['unchanged'])->toBeTrue();
});

// --- all or nothing and the row rules ---------------------------------------------------------------------------

it('saves nothing when one row of ten has an error, and lists the line and reason', function () {
    $hr = userWithRole('hr');
    $rows = [];
    for ($i = 1; $i <= 10; $i++) {
        $rows[] = ariRow(['Name' => "Asset {$i}", 'Serial' => "S-{$i}", 'Category' => $i === 7 ? 'Tablets' : 'Laptop']);
    }

    $preview = ariPreview($hr, ariXlsx($rows));

    expect($preview['totals'])->toBe(['rows' => 10, 'ready' => 9, 'errors' => 1, 'unchanged' => 0])
        ->and($preview['rows'][6]['line'])->toBe(8)
        ->and($preview['rows'][6]['errors'])->toBe(['Category Tablets does not exist. Add it under Settings > Asset categories first.']);

    $activities = Activity::query()->count();
    ariRun($hr, $preview['token'])->assertSessionHas('error', EmployeeImportController::CHANGED);

    expect(Asset::query()->count())->toBe(0)->and(Activity::query()->count())->toBe($activities);
});

it('checks each column of a row', function (array $override, ?string $error) {
    $hr = userWithRole('hr');
    ariSara();
    aaPerson(['employee_code' => 'LD-0102', 'first_name' => 'Ali', 'last_name' => 'Raza', 'status' => 'resigned']);

    $errors = ariPreview($hr, ariXlsx([ariRow($override)]))['rows'][0]['errors'];

    $error === null ? expect($errors)->toBe([]) : expect($errors)->toContain($error);
})->with([
    'name blank' => [['Name' => null], 'Name is required.'],
    'name too long' => [['Name' => str_repeat('a', 121)], 'Name must be 120 characters or fewer.'],
    'unknown category' => [['Category' => 'Tablets'], 'Category Tablets does not exist. Add it under Settings > Asset categories first.'],
    'category in other case' => [['Category' => 'LAPTOP'], null],
    'category blank' => [['Category' => null], 'Category is required.'],
    'serial too long' => [['Serial' => str_repeat('s', 101)], 'Serial must be 100 characters or fewer.'],
    'purchase date not a date' => [['Purchase Date' => '2026-13-01'], 'Purchase Date must be a date written as YYYY-MM-DD, for example 2026-03-15.'],
    'purchase date in the future' => [['Purchase Date' => '2026-11-27'], 'Purchase Date cannot be in the future.'],
    'purchase date as an Excel date' => [['Purchase Date' => 46037], null],
    'purchase date as a bare number' => [['Purchase Date' => 2026], 'Purchase Date must be a date written as YYYY-MM-DD, for example 2026-03-15.'],
    'cost negative' => [['Cost' => -5], 'Cost must be whole rupees from 0 to 99,999,999.'],
    'cost with paisa' => [['Cost' => 10.5], 'Cost must be whole rupees from 0 to 99,999,999.'],
    'cost too big' => [['Cost' => 100000000], 'Cost must be whole rupees from 0 to 99,999,999.'],
    'cost with commas' => [['Cost' => '1,000'], null],
    'condition unknown' => [['Condition' => 'Broken'], 'Condition must be one of New, Good, Fair, Poor, Damaged.'],
    'condition blank on a new asset' => [['Condition' => null], 'Condition is required for a new asset.'],
    'status unknown' => [['Status' => 'Lost'], 'Status must be one of Available, Assigned, In Repair, Retired, or blank.'],
    'status in repair' => [['Status' => 'in repair'], null],
    'unknown code' => [['Asset Code' => 'AST-0099'], 'No asset has the code AST-0099.'],
    'unknown holder' => [['Holder Employee Code' => 'ZZ-9999', 'Assigned Date' => '2026-11-20'], 'No employee has the code ZZ-9999.'],
    'holder without a date' => [['Holder Employee Code' => 'LD-0101'], 'Assigned Date is required with a Holder Employee Code.'],
    'date without a holder' => [['Assigned Date' => '2026-11-20'], 'Assigned Date needs a Holder Employee Code (leave it blank otherwise).'],
    'assigned without a holder' => [['Status' => 'Assigned'], 'Status Assigned needs a Holder Employee Code.'],
    'holder with another status' => [['Status' => 'In Repair', 'Holder Employee Code' => 'LD-0101', 'Assigned Date' => '2026-11-20'], 'Status must be Assigned (or blank) when a Holder Employee Code is given.'],
    'holder not active' => [['Holder Employee Code' => 'LD-0102', 'Assigned Date' => '2026-11-20'], 'Ali Raza is not an active employee, so assets cannot be given to them.'],
    'assigned date in the future' => [['Holder Employee Code' => 'LD-0101', 'Assigned Date' => '2026-11-27'], 'Assigned Date cannot be in the future.'],
    'assigned date before joining' => [['Holder Employee Code' => 'LD-0101', 'Assigned Date' => '2025-01-14'], 'Assigned Date cannot be before Sara Ahmed joined (15 Jan 2025).'],
    'assigned date on the joining day' => [['Holder Employee Code' => 'LD-0101', 'Assigned Date' => '2025-01-15'], null],
    'assigned date not a date' => [['Holder Employee Code' => 'LD-0101', 'Assigned Date' => '20/11/2026'], 'Assigned Date must be a date written as YYYY-MM-DD, for example 2026-11-20.'],
]);

it('refuses the same asset or the same serial twice in the file, on each line', function () {
    $hr = userWithRole('hr');
    assetRow(['asset_code' => 'AST-0001', 'serial_number' => 'OLD-1']);

    $rows = ariPreview($hr, ariXlsx([
        ariRow(['Asset Code' => 'AST-0001', 'Serial' => null]),
        ariRow(['Serial' => 'NEW-1']),
        ariRow(['Asset Code' => 'AST-0001', 'Serial' => null]),
        ariRow(['Serial' => 'new-1']),
    ]))['rows'];

    expect($rows[0]['errors'])->toBe(['Asset AST-0001 appears more than once in the file (rows 2, 4).'])
        ->and($rows[2]['errors'])->toBe(['Asset AST-0001 appears more than once in the file (rows 2, 4).'])
        ->and($rows[1]['errors'])->toBe(['Serial NEW-1 appears more than once in the file (rows 3, 5).'])
        ->and($rows[3]['errors'])->toBe(['Serial new-1 appears more than once in the file (rows 3, 5).']);
});

it('refuses a serial that belongs to another asset than the code names', function () {
    $hr = userWithRole('hr');
    assetRow(['asset_code' => 'AST-0001', 'serial_number' => 'ONE']);
    assetRow(['asset_code' => 'AST-0002', 'serial_number' => 'TWO']);

    $rows = ariPreview($hr, ariXlsx([ariRow(['Asset Code' => 'AST-0001', 'Serial' => 'two'])]))['rows'];

    expect($rows[0]['errors'])->toBe(['Serial two belongs to another asset (AST-0002).']);
});

it('lets only an Admin import a retired row or bring a retired asset back', function () {
    $hr = userWithRole('hr');
    $admin = userWithRole('admin');
    assetRow(['asset_code' => 'AST-0001', 'serial_number' => 'OLD', 'name' => 'Old', 'status' => 'retired', 'condition' => 'poor']);

    $retireNew = ariXlsx([ariRow(['Status' => 'Retired'])]);
    $restore = fn () => ariXlsx([['AST-0001', 'Old', 'Laptop', null, null, null, null, 'Available', null, null]]);

    expect(ariPreview($hr, $retireNew)['rows'][0]['errors'])->toBe(['Only an Admin can retire an asset.'])
        ->and(ariPreview($hr, $restore())['rows'][0]['errors'])->toBe(['Only an Admin can bring a retired asset back.']);

    $preview = ariPreview($admin, ariXlsx([ariRow(['Status' => 'Retired'])]));
    expect($preview['rows'][0]['errors'])->toBe([]);
    ariRun($admin, $preview['token'])->assertSessionHas('success');
    expect(Asset::query()->where('serial_number', 'SN-1')->value('status'))->toBe('retired');

    $back = ariPreview($admin, $restore());
    expect($back['rows'][0]['errors'])->toBe([]);
    ariRun($admin, $back['token'])->assertSessionHas('success');
    expect(Asset::query()->where('asset_code', 'AST-0001')->value('status'))->toBe('available');
});

it('refuses rows that would take a held asset away from its holder, and never returns an asset', function () {
    $hr = userWithRole('hr');
    $sara = ariSara();
    aaPerson(['employee_code' => 'LD-0103', 'first_name' => 'Ali', 'last_name' => 'Khan']);
    assignedAsset($sara, ['asset_code' => 'AST-0001', 'serial_number' => 'H-1', 'name' => 'Held', 'condition' => 'good']);
    assetRow(['asset_code' => 'AST-0002', 'serial_number' => 'R-2', 'name' => 'In repair', 'status' => 'in_repair']);

    $rows = ariPreview($hr, ariXlsx([
        ['AST-0001', 'Held', 'Laptop', null, null, null, null, null, 'LD-0103', '2026-11-25'],
        ['AST-0001', 'Held', 'Laptop', null, null, null, null, 'Available', null, null],
        ['AST-0001', 'Held', 'Laptop', null, null, null, null, 'In Repair', null, null],
        ['AST-0002', 'In repair', 'Laptop', null, null, null, null, null, 'LD-0103', '2026-11-25'],
    ]))['rows'];

    expect($rows[0]['errors'])->toContain('AST-0001 is held by Sara Ahmed. Return it on the asset page first.')
        ->and($rows[1]['errors'])->toContain('AST-0001 is held by Sara Ahmed. Return it on the asset page first.')
        ->and($rows[2]['errors'])->toContain('AST-0001 is held by Sara Ahmed. Return it on the asset page first.')
        ->and($rows[3]['errors'])->toBe(['This asset is not available. It is in repair.']);

    // One at a time, each alone in its file: still refused, and nothing is returned.
    foreach ([
        ['AST-0001', 'Held', 'Laptop', null, null, null, null, null, 'LD-0103', '2026-11-25'],
        ['AST-0001', 'Held', 'Laptop', null, null, null, null, 'Retired', null, null],
    ] as $row) {
        $preview = ariPreview(userWithRole('admin'), ariXlsx([$row]));
        expect($preview['rows'][0]['errors'])->toBe(['AST-0001 is held by Sara Ahmed. Return it on the asset page first.']);
    }

    // A blank holder on a held asset keeps it held.
    $keep = ariPreview($hr, ariXlsx([['AST-0001', 'Renamed', 'Laptop', null, null, null, null, null, null, null]]));
    ariRun($hr, $keep['token'])->assertSessionHas('success');
    expect(AssetAssignment::query()->open()->where('employee_id', $sara->id)->count())->toBe(1)
        ->and(Asset::query()->where('asset_code', 'AST-0001')->value('status'))->toBe('assigned');
    assertAssetInvariant();
});

// --- races, the lock, failures ----------------------------------------------------------------------------------

it('refuses the run when the asset was assigned by hand after the preview, and saves nothing', function () {
    $hr = userWithRole('hr');
    $sara = ariSara();
    $ali = aaPerson(['employee_code' => 'LD-0103', 'first_name' => 'Ali', 'last_name' => 'Khan']);
    $asset = assetRow(['asset_code' => 'AST-0001', 'serial_number' => 'X-1', 'name' => 'Laptop X']);

    $preview = ariPreview($hr, ariXlsx([
        ariRow(['Name' => 'Another', 'Serial' => 'NEW-9']),
        ['AST-0001', 'Laptop X', 'Laptop', null, null, null, null, null, 'LD-0101', '2026-11-25'],
    ]));
    expect($preview['totals']['errors'])->toBe(0);

    app(AssignAsset::class)->handle($hr, $asset, $ali, '2026-11-26', null);

    ariRun($hr, $preview['token'])->assertRedirect('/assets/import?token='.$preview['token'])
        ->assertSessionHas('error', EmployeeImportController::CHANGED);

    expect(Asset::query()->count())->toBe(1)
        ->and(AssetAssignment::query()->sole()->employee_id)->toBe($ali->id)
        ->and(ariImports())->toBe(0)
        ->and($sara->fresh())->not->toBeNull();

    $again = ariPreview($hr, ariXlsx([['AST-0001', 'Laptop X', 'Laptop', null, null, null, null, null, 'LD-0101', '2026-11-25']]));
    expect($again['rows'][0]['errors'])->toBe(['AST-0001 is held by Ali Khan. Return it on the asset page first.']);
});

it('checks the rows again under the locks: a hand-over made just as the run begins is refused', function () {
    $hr = userWithRole('hr');
    ariSara();
    $ali = aaPerson(['employee_code' => 'LD-0103', 'first_name' => 'Ali', 'last_name' => 'Khan']);
    $asset = assetRow(['asset_code' => 'AST-0001', 'serial_number' => 'X-1', 'name' => 'Laptop X']);

    $preview = ariPreview($hr, ariXlsx([['AST-0001', 'Laptop X', 'Laptop', null, null, null, null, null, 'LD-0101', '2026-11-25']]));

    // After the run's first check, when its transaction opens: someone else's assignment appears (here written on the
    // same connection, so it is rolled back with the import; what matters is that the import refuses).
    $fired = false;
    Event::listen(TransactionBeginning::class, function () use (&$fired, $asset, $ali): void {
        if (! $fired) {
            $fired = true;
            AssetAssignment::query()->create(['asset_id' => $asset->id, 'employee_id' => $ali->id, 'assigned_on' => '2026-11-26', 'condition_out' => 'good']);
            Asset::query()->whereKey($asset->id)->update(['status' => 'assigned']);
        }
    });

    ariRun($hr, $preview['token'])->assertSessionHas('error', EmployeeImportController::CHANGED);

    expect($fired)->toBeTrue()
        ->and(AssetAssignment::query()->where('employee_id', '!=', $ali->id)->count())->toBe(0)
        ->and(ariImports())->toBe(0)
        ->and(Activity::query()->where('description', 'Asset assigned')->count())->toBe(0);
});

it('runs one import at a time, whatever the upload token', function () {
    $hr = userWithRole('hr');
    $preview = ariPreview($hr, ariXlsx([ariRow()]));

    $lock = Cache::lock(AssetImportController::LOCK, 60);
    expect($lock->get())->toBeTrue();
    ariRun($hr, $preview['token'])->assertSessionHas('error', EmployeeImportController::BUSY);
    expect(Asset::query()->count())->toBe(0);
    $lock->release();

    ariRun($hr, $preview['token'])->assertSessionHas('success');
    expect(Asset::query()->count())->toBe(1);

    // The token is single use.
    ariRun($hr, $preview['token'])->assertSessionHas('error', EmployeeImportController::REFUSED);
});

it('shows "The import failed" on an unexpected error, saves nothing and keeps the upload', function () {
    $hr = userWithRole('hr');
    $preview = ariPreview($hr, ariXlsx([ariRow(['Serial' => 'A']), ariRow(['Serial' => 'B'])]));

    $this->app->instance(SaveAsset::class, new class extends SaveAsset
    {
        private int $calls = 0;

        public function within(User $by, ?Asset $locked, array $data): Asset
        {
            if (++$this->calls === 2) {
                throw new RuntimeException('disk full');
            }

            return parent::within($by, $locked, $data);
        }
    });

    ariRun($hr, $preview['token'])->assertSessionHas('error', EmployeeImportController::FAILED);
    expect(Asset::query()->count())->toBe(0)->and(ariImports())->toBe(0);

    $this->app->forgetInstance(SaveAsset::class);
    ariRun($hr, $preview['token'])->assertSessionHas('success');
    expect(Asset::query()->count())->toBe(2);
});

it('keeps costs out of nothing but the HR and Admin preview, which the route already guards', function () {
    $hr = userWithRole('hr');
    $preview = ariPreview($hr, ariXlsx([ariRow()]));

    expect($preview['rows'][0]['cost'])->toBe('Rs 185,000');
    $this->actingAs(userWithRole('manager'))->get('/assets/import?token='.$preview['token'])->assertForbidden();
});

it('checks a file with a fixed number of queries, whatever the row count', function () {
    $hr = userWithRole('hr');
    for ($i = 1; $i <= 20; $i++) {
        $person = aaPerson(['employee_code' => sprintf('LD-%04d', $i)]);
        $i % 2 === 0
            ? assignedAsset($person, ['asset_code' => sprintf('AST-%04d', $i), 'serial_number' => "S-{$i}"])
            : assetRow(['asset_code' => sprintf('AST-%04d', $i), 'serial_number' => "S-{$i}"]);
    }

    $rows = fn (int $count) => array_map(fn (int $i) => ['line' => $i + 1, 'cells' => array_combine(
        array_values(AssetRegisterImport::columns()),
        [sprintf('AST-%04d', $i), "Asset {$i}", 'Laptop', "S-{$i}", '2026-01-15', 1000, 'Good', $i === 1 ? 'In Repair' : null, sprintf('LD-%04d', $i), '2026-11-20'],
    )], range(1, $count));

    $queries = function (array $rows) use ($hr): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        (new AssetRegisterImport)->check($rows, $hr);
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };

    // A first run warms the permission cache (statusRefusal asks the user's permissions).
    $queries($rows(2));

    expect($queries($rows(2)))->toBe(5)->toBe($queries($rows(20)));
});
