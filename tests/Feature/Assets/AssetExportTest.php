<?php

use App\Models\Asset;
use App\Models\AssetAssignment;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/*
 * Assets > Export: GET /assets/export with the list's filters, every matching row, the template's columns plus Holder
 * Name, audited before the file is built, and a round trip through the importer. Today is 2026-11-26.
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

/** The downloaded workbook's first sheet. */
function aeSheet(BinaryFileResponse $response): Worksheet
{
    return IOFactory::createReader('Xlsx')->load($response->getFile()->getPathname())->getSheet(0);
}

function aeSeed(): array
{
    $sara = aaPerson(['employee_code' => 'LD-0101']);
    $laptop = assignedAsset($sara, [
        'asset_code' => 'AST-0001', 'name' => 'Dell Latitude', 'serial_number' => 'SN-1', 'purchase_date' => '2026-01-15',
        'cost' => 18500000, 'condition' => 'good', 'assigned_on' => '2026-11-10',
    ]);
    $phone = assetRow(['asset_code' => 'AST-0002', 'name' => 'Pixel', 'serial_number' => null, 'purchase_date' => null, 'cost' => null, 'condition' => 'fair', 'category' => 'Phone', 'status' => 'in_repair']);
    $old = assetRow(['asset_code' => 'AST-0003', 'name' => '=1+1', 'serial_number' => '+92-SN', 'cost' => 0, 'condition' => 'poor', 'status' => 'retired']);

    return [$sara, $laptop, $phone, $old];
}

test('only HR and Admin may export', function (string $role, int $status) {
    $this->actingAs(userWithRole($role))->get('/assets/export')->assertStatus($status);
})->with([['admin', 200], ['hr', 200], ['manager', 403], ['employee', 403]]);

it('puts can:assets.assign on the export route', function () {
    expect(app('router')->getRoutes()->getByName('assets.export')?->gatherMiddleware())->toContain('can:assets.assign');
    $this->get('/assets/export')->assertRedirect('/login');
});

it('exports every asset with the template\'s columns plus Holder Name, cost as a number and dates as text', function () {
    aeSeed();

    $response = $this->actingAs(userWithRole('hr'))->get('/assets/export')->assertOk();
    expect($response->headers->get('content-disposition'))->toContain('assets-2026-11-26.xlsx');
    $sheet = aeSheet($response->baseResponse);

    expect($sheet->rangeToArray('A1:K1')[0])->toBe([...ARI_HEADERS, 'Holder Name'])
        ->and($sheet->rangeToArray('A2:K2', null, true, false)[0])->toBe(['AST-0001', 'Dell Latitude', 'Laptop', 'SN-1', '2026-01-15', 185000, 'Good', 'Assigned', 'LD-0101', '2026-11-10', 'Sara Ahmed'])
        ->and($sheet->rangeToArray('A3:K3', null, true, false)[0])->toBe(['AST-0002', 'Pixel', 'Phone', null, null, null, 'Fair', 'In Repair', null, null, null])
        ->and($sheet->getCell('F2')->getDataType())->toBe(DataType::TYPE_NUMERIC)
        ->and($sheet->getCell('E2')->getDataType())->toBe(DataType::TYPE_STRING)
        ->and($sheet->getCell('J2')->getDataType())->toBe(DataType::TYPE_STRING)
        // A formula-looking name stays text, with the quote prefix; never a formula.
        ->and($sheet->getCell('B4')->getDataType())->toBe(DataType::TYPE_STRING)
        ->and($sheet->getCell('B4')->getValue())->toBe('=1+1')
        ->and($sheet->getStyle('B4')->getQuotePrefix())->toBeTrue()
        ->and($sheet->getCell('D4')->getValue())->toBe('+92-SN')
        ->and($sheet->getCell('F4')->getValue())->toBe(0);
});

it('applies the list\'s filters and audits the export with counts and filters, never rows', function () {
    aeSeed();
    $hr = userWithRole('hr');

    $sheet = aeSheet($this->actingAs($hr)->get('/assets/export?status=in_repair&category='.assetCategory('Phone')->id.'&condition=fair&search=pix')->baseResponse);
    expect($sheet->getHighestRow())->toBe(2)->and($sheet->getCell('A2')->getValue())->toBe('AST-0002');

    $bySearch = aeSheet($this->actingAs($hr)->get('/assets/export?search=sara')->baseResponse);
    expect($bySearch->getHighestRow())->toBe(2)->and($bySearch->getCell('A2')->getValue())->toBe('AST-0001');

    // A filter value the list would not accept is ignored.
    expect(aeSheet($this->actingAs($hr)->get('/assets/export?status=lost&category=abc')->baseResponse)->getHighestRow())->toBe(4);

    $entry = Activity::query()->where('description', 'Assets exported')->orderBy('id')->first();
    expect($entry->log_name)->toBe('assets')
        ->and($entry->subject_id)->toBeNull()
        ->and($entry->causer_id)->toBe($hr->id)
        ->and(Arr::except($entry->properties->toArray(), ['ip', 'user_agent']))->toBe([
            'rows' => 1,
            'filters' => ['search' => 'pix', 'category' => assetCategory('Phone')->id, 'status' => 'in_repair', 'condition' => 'fair'],
            'columns' => 11,
        ])
        ->and(json_encode($entry->properties))->not->toContain('Pixel')->not->toContain('AST-0002');
});

it('round-trips through the importer unchanged', function () {
    aeSeed();
    $admin = userWithRole('admin');
    $before = Asset::query()->orderBy('id')->get()->toArray();

    $path = $this->actingAs($admin)->get('/assets/export')->baseResponse->getFile()->getPathname();
    $preview = ariPreview($admin, ariFile($path));

    expect($preview['totals'])->toBe(['rows' => 3, 'ready' => 3, 'errors' => 0, 'unchanged' => 3])
        ->and($preview['notices'])->toBe(['These columns are not part of the template and were ignored: Holder Name.']);

    ariRun($admin, $preview['token'])->assertSessionHas('success', 'Imported 3 rows: 0 added, 0 updated, 3 already up to date.');
    expect(Asset::query()->orderBy('id')->get()->toArray())->toBe($before)
        ->and(AssetAssignment::query()->count())->toBe(1);
});

it('lets HR re-import an export holding a retired asset unchanged', function () {
    aeSeed();
    $hr = userWithRole('hr');

    $path = $this->actingAs($hr)->get('/assets/export')->baseResponse->getFile()->getPathname();
    $preview = ariPreview($hr, ariFile($path));

    expect($preview['totals']['errors'])->toBe(0)->and($preview['totals']['unchanged'])->toBe(3);
});

it('exports just the header row for an empty register', function () {
    $sheet = aeSheet($this->actingAs(userWithRole('hr'))->get('/assets/export')->baseResponse);

    expect($sheet->getHighestRow())->toBe(1)->and($sheet->rangeToArray('A1:K1')[0])->toBe([...ARI_HEADERS, 'Holder Name']);
});
