<?php

namespace App\Imports;

use App\Actions\Assets\AssignAsset;
use App\Models\Asset;
use App\Models\AssetAssignment;
use App\Models\AssetCategory;
use App\Models\Employee;
use App\Models\User;
use App\Support\Assets\AssetRules;
use App\Support\Attendance\AttendanceClock;
use App\Support\Names;
use App\Support\Payroll\Money;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use Throwable;

/**
 * The asset register workbook (Assets > Import). Columns, in the template's order (Asset Code may be left out):
 *
 *   Asset Code | Name | Category | Serial | Purchase Date | Cost | Condition | Status | Holder Employee Code | Assigned Date
 *
 * The file is read by PayrollOpeningWorkbook (the same file guards as the go-live payroll imports). check() validates every
 * row with a fixed number of queries whatever the row count: the categories, the assets named by code or serial, the
 * holders by employee code, and the open assignments of those assets (plain reads, never a lock).
 *
 * Which asset a row is: an Asset Code must name an existing asset (that asset is updated); without one, a Serial that
 * matches an asset (ignoring case) updates it; otherwise the row is a new asset, whose code is given in file order when
 * the run saves it. On an update a blank Serial, Purchase Date, Cost, Condition or Status keeps what is stored.
 *
 * A holder hands the asset over (AssignAsset's rules: an active or probation employee, a date not in the future and not
 * before joining). The import never returns an asset: one held by someone else, or a status other than Assigned on a
 * held asset, is an error ("Return it on the asset page first."). The same holder again changes nothing (a different
 * date is only a warning). Status changes follow AssetRules::statusRefusal for the importing user, so HR cannot import
 * Retired or bring a retired asset back.
 */
final class AssetRegisterImport extends PayrollOpeningWorkbook
{
    public const NAME_MAX = 120;

    public const SERIAL_MAX = 100;

    public const RETURN_FIRST = 'Return it on the asset page first.';

    public static function columns(): array
    {
        return [
            'Asset Code' => 'code',
            'Name' => 'name',
            'Category' => 'category',
            'Serial' => 'serial',
            'Purchase Date' => 'purchase_date',
            'Cost' => 'cost',
            'Condition' => 'condition',
            'Status' => 'status',
            'Holder Employee Code' => 'holder',
            'Assigned Date' => 'assigned_on',
        ];
    }

    public static function optional(): array
    {
        return ['code'];
    }

    /**
     * "Y-m-d" from text written that way, or from a date Excel made of a typed date (a serial number); null otherwise.
     */
    public static function date(mixed $value): ?string
    {
        if (is_int($value) || is_float($value)) {
            // A real date: 1990-01-01 (32874) to 2099-12-31 (73415). A bare 7 or 2026 is a typing slip.
            if ($value < 32874 || $value > 73415 || fmod((float) $value, 1.0) !== 0.0) {
                return null;
            }

            try {
                return ExcelDate::excelToDateTimeObject((float) $value)->format('Y-m-d');
            } catch (Throwable) {
                return null;
            }
        }

        $text = self::text($value);

        return AssignAsset::isDate($text) ? $text : null;
    }

    /**
     * A condition or status as typed ("In repair", "in_repair", "IN REPAIR") as its stored key, or null.
     *
     * @param  list<string>  $allowed
     */
    public static function choice(string $text, array $allowed): ?string
    {
        $key = str_replace([' ', '-'], '_', mb_strtolower(trim($text)));

        return in_array($key, $allowed, true) ? $key : null;
    }

    /** "In Repair" for in_repair: how the template and the export write a stored key. */
    public static function label(?string $key): string
    {
        return EmployeeSheet::enumLabel($key);
    }

    /**
     * @param  list<array{line: int, cells: array<string, mixed>}>  $rows
     * @return list<array{line: int, display: array<string, string>, errors: list<string>, warnings: list<string>, unchanged: bool, action: 'create'|'update'|'none', data: array<string, mixed>|null}>
     */
    public function check(array $rows, User $user): array
    {
        $conditions = self::configList('hrm.assets.conditions');
        $statuses = self::configList('hrm.assets.statuses');
        $clock = app(AttendanceClock::class);
        $calendarToday = $clock->today()->toDateString();
        $attendanceToday = $clock->attendanceToday();

        [$codes, $serials, $holders] = self::keys($rows);

        // 1. Every category (a short list), keyed by its name in lower case.
        $categories = AssetCategory::query()->get(['id', 'name'])->keyBy(fn (AssetCategory $c) => mb_strtolower($c->name));

        // 2. The assets named by code or by serial, in one query.
        $assets = $codes === [] && $serials === []
            ? collect()
            : Asset::query()
                ->where(function ($q) use ($codes, $serials) {
                    if ($codes !== []) {
                        $q->orWhereIn('asset_code', $codes);
                    }
                    if ($serials !== []) {
                        $q->orWhereIn(DB::raw('LOWER(serial_number)'), $serials);
                    }
                })
                ->orderBy('id')
                ->get();
        $byCode = $assets->keyBy(fn (Asset $a) => mb_strtoupper($a->asset_code));
        $bySerial = $assets->filter(fn (Asset $a) => $a->serial_number !== null)->keyBy(fn (Asset $a) => mb_strtolower((string) $a->serial_number));

        // 3. The holders by employee code.
        $people = $holders === []
            ? collect()
            : Employee::query()->whereIn('employee_code', $holders)->get(['id', 'employee_code', 'first_name', 'last_name', 'status', 'joining_date'])
                ->keyBy(fn (Employee $e) => mb_strtoupper((string) $e->employee_code));

        // 4. The open assignments of those assets, with their holders (plain reads).
        /** @var Collection<int, AssetAssignment> $open */
        $open = $assets->isEmpty()
            ? collect()
            : AssetAssignment::query()->open()->whereIn('asset_id', $assets->pluck('id')->all())
                ->with('employee:id,employee_code,first_name,last_name')
                ->get(['id', 'asset_id', 'employee_id', 'assigned_on'])
                ->keyBy('asset_id');

        $checked = [];
        $assetLines = [];
        $serialLines = [];

        foreach ($rows as $row) {
            $checked[] = $this->checkRow($row, $user, $categories, $byCode, $bySerial, $people, $open, $conditions, $statuses, $calendarToday, $attendanceToday);
            $last = $checked[array_key_last($checked)];

            if ($last['asset'] !== null) {
                $assetLines[$last['asset']->id][] = $row['line'];
            }

            if ($last['serial_key'] !== null) {
                $serialLines[$last['serial_key']][] = $row['line'];
            }
        }

        $result = [];

        foreach ($checked as $row) {
            if ($row['asset'] !== null && count($assetLines[$row['asset']->id]) > 1) {
                $row['errors'][] = "Asset {$row['asset']->asset_code} appears more than once in the file (rows ".implode(', ', $assetLines[$row['asset']->id]).').';
            }

            if ($row['serial_key'] !== null && count($serialLines[$row['serial_key']]) > 1) {
                $row['errors'][] = 'Serial '.$row['display']['serial'].' appears more than once in the file (rows '.implode(', ', $serialLines[$row['serial_key']]).').';
            }

            $ok = $row['errors'] === [];

            $result[] = [
                'line' => $row['line'],
                'display' => [...$row['display'], 'action' => $ok ? self::actionLabel($row['action']) : ''],
                'errors' => $row['errors'],
                'warnings' => $ok ? $row['warnings'] : [],
                'unchanged' => $ok && $row['action'] === 'none',
                'action' => $row['action'],
                'data' => $ok ? $row['data'] : null,
            ];
        }

        return $result;
    }

    /**
     * The allowed values a config key lists (the asset conditions or statuses).
     *
     * @return list<string>
     */
    public static function configList(string $key): array
    {
        return array_values(array_filter((array) config($key), 'is_string'));
    }

    private static function actionLabel(string $action): string
    {
        return match ($action) {
            'create' => 'New asset',
            'update' => 'Updates the asset',
            default => 'No change',
        };
    }

    /**
     * The codes (upper case), serials (lower case) and holder codes (upper case) the rows name.
     *
     * @param  list<array{line: int, cells: array<string, mixed>}>  $rows
     * @return array{0: list<string>, 1: list<string>, 2: list<string>}
     */
    private static function keys(array $rows): array
    {
        $codes = $serials = $holders = [];

        foreach ($rows as $row) {
            $code = mb_strtoupper(self::text($row['cells']['code'] ?? null));
            $serial = mb_strtolower(self::text($row['cells']['serial'] ?? null));
            $holder = mb_strtoupper(self::text($row['cells']['holder'] ?? null));

            if ($code !== '') {
                $codes[$code] = true;
            }
            if ($serial !== '' && mb_strlen($serial) <= self::SERIAL_MAX) {
                $serials[$serial] = true;
            }
            if ($holder !== '') {
                $holders[$holder] = true;
            }
        }

        return [array_keys($codes), array_keys($serials), array_keys($holders)];
    }

    /**
     * One row on its own (the file-wide duplicate checks come afterwards).
     *
     * @param  array{line: int, cells: array<string, mixed>}  $row
     * @param  Collection<string, AssetCategory>  $categories
     * @param  Collection<string, Asset>  $byCode
     * @param  Collection<string, Asset>  $bySerial
     * @param  Collection<string, Employee>  $people
     * @param  Collection<int, AssetAssignment>  $open
     * @param  list<string>  $conditions
     * @param  list<string>  $statuses
     * @return array{line: int, display: array<string, string>, errors: list<string>, warnings: list<string>, action: 'create'|'update'|'none', asset: Asset|null, serial_key: string|null, data: array<string, mixed>|null}
     */
    private function checkRow(
        array $row, User $user, Collection $categories, Collection $byCode, Collection $bySerial, Collection $people,
        Collection $open, array $conditions, array $statuses, string $calendarToday, string $attendanceToday,
    ): array {
        $cells = $row['cells'];
        $errors = [];
        $warnings = [];

        $codeText = mb_strtoupper(self::text($cells['code'] ?? null));
        $name = (string) Names::normalise(self::text($cells['name'] ?? null));
        $categoryText = self::text($cells['category'] ?? null);
        $serial = self::text($cells['serial'] ?? null);
        $purchaseText = self::text($cells['purchase_date'] ?? null);
        $costText = self::text($cells['cost'] ?? null);
        $conditionText = self::text($cells['condition'] ?? null);
        $statusText = self::text($cells['status'] ?? null);
        $holderText = mb_strtoupper(self::text($cells['holder'] ?? null));
        $assignedText = self::text($cells['assigned_on'] ?? null);

        // Which asset: by code, else by serial, else new.
        $asset = null;
        $codeFailed = false;

        if ($codeText !== '') {
            $asset = $byCode->get($codeText);

            if ($asset === null) {
                $errors[] = "No asset has the code {$codeText}.";
                $codeFailed = true;
            }
        }

        $serialKey = null;

        if ($serial !== '') {
            if (mb_strlen($serial) > self::SERIAL_MAX) {
                $errors[] = 'Serial must be '.self::SERIAL_MAX.' characters or fewer.';
            } else {
                $serialKey = mb_strtolower($serial);
                $owner = $bySerial->get($serialKey);

                if ($asset !== null && $owner !== null && $owner->id !== $asset->id) {
                    $errors[] = "Serial {$serial} belongs to another asset ({$owner->asset_code}).";
                } elseif ($asset === null && ! $codeFailed && $owner !== null) {
                    $asset = $owner;
                }
            }
        }

        $isNew = $asset === null;

        if ($name === '') {
            $errors[] = 'Name is required.';
        } elseif (mb_strlen($name) > self::NAME_MAX) {
            $errors[] = 'Name must be '.self::NAME_MAX.' characters or fewer.';
        }

        $category = null;

        if ($categoryText === '') {
            $errors[] = 'Category is required.';
        } else {
            $category = $categories->get(mb_strtolower($categoryText));

            if ($category === null) {
                $errors[] = "Category {$categoryText} does not exist. Add it under Settings > Asset categories first.";
            }
        }

        $purchase = null;

        if ($purchaseText !== '') {
            $purchase = self::date($cells['purchase_date'] ?? null);

            if ($purchase === null) {
                $errors[] = 'Purchase Date must be a date written as YYYY-MM-DD, for example 2026-03-15.';
            } elseif ($purchase > $calendarToday) {
                $errors[] = 'Purchase Date cannot be in the future.';
            }
        }

        $cost = null;

        if ($costText !== '') {
            $cost = self::wholeRupees($cells['cost'] ?? null);

            if ($cost === null) {
                $errors[] = 'Cost must be whole rupees from 0 to 99,999,999.';
            }
        }

        $condition = null;

        if ($conditionText !== '') {
            $condition = self::choice($conditionText, $conditions);

            if ($condition === null) {
                $errors[] = 'Condition must be one of '.implode(', ', array_map(self::label(...), $conditions)).'.';
            }
        } elseif ($isNew) {
            $errors[] = 'Condition is required for a new asset.';
        }

        $status = null;

        if ($statusText !== '') {
            $status = self::choice($statusText, $statuses);

            if ($status === null) {
                $errors[] = 'Status must be one of '.implode(', ', array_map(self::label(...), $statuses)).', or blank.';
            }
        }

        // The holder and the hand-over.
        $holder = null;
        $assign = false;
        $assignedOn = null;
        $openRow = $asset === null ? null : $open->get($asset->id);
        $held = $asset !== null && ($asset->status === 'assigned' || $openRow !== null);
        $heldBy = $openRow?->employee->full_name ?? 'an employee';
        $currentStatus = $asset === null ? 'available' : $asset->status;

        if ($holderText !== '') {
            $holder = $people->get($holderText);

            if ($holder === null) {
                $errors[] = "No employee has the code {$holderText}.";
            }

            if ($status !== null && $status !== 'assigned') {
                $errors[] = 'Status must be Assigned (or blank) when a Holder Employee Code is given.';
            }

            if ($assignedText === '') {
                $errors[] = 'Assigned Date is required with a Holder Employee Code.';
            } else {
                $assignedOn = self::date($cells['assigned_on'] ?? null);

                if ($assignedOn === null) {
                    $errors[] = 'Assigned Date must be a date written as YYYY-MM-DD, for example 2026-11-20.';
                }
            }

            if ($holder !== null && $asset !== null && $held) {
                if ($openRow !== null && $openRow->employee_id === $holder->id) {
                    $since = $openRow->assigned_on->format('Y-m-d');

                    if ($assignedOn !== null && $assignedOn !== $since) {
                        $warnings[] = "Assigned date ignored: held since {$since}.";
                    }
                } else {
                    $errors[] = "{$asset->asset_code} is held by {$heldBy}. ".self::RETURN_FIRST;
                }
            } elseif ($holder !== null) {
                if ($currentStatus !== 'available') {
                    $errors[] = AssignAsset::notAvailableMessage($currentStatus);
                } elseif (! in_array($holder->status, AssignAsset::ASSIGNABLE_EMPLOYEE_STATUSES, true)) {
                    $errors[] = AssignAsset::notActiveMessage($holder->full_name);
                } elseif ($assignedOn !== null) {
                    $joined = $holder->joining_date->format('Y-m-d');

                    if ($assignedOn > $attendanceToday) {
                        $errors[] = 'Assigned Date cannot be in the future.';
                    } elseif ($assignedOn < $joined) {
                        $errors[] = 'Assigned Date cannot be before '.$holder->full_name.' joined ('.AssignAsset::human($joined).').';
                    }
                }

                $assign = true;
            }
        } else {
            if ($assignedText !== '') {
                $errors[] = 'Assigned Date needs a Holder Employee Code (leave it blank otherwise).';
            }

            if ($status === 'assigned') {
                $errors[] = 'Status Assigned needs a Holder Employee Code.';
            }
        }

        // A status change by hand (never to or from assigned; never on a held asset).
        $statusTo = null;

        if ($status !== null && $status !== 'assigned' && $status !== $currentStatus) {
            if ($held) {
                $errors[] = ($asset->asset_code ?? 'This asset')." is held by {$heldBy}. ".self::RETURN_FIRST;
            } else {
                $refusal = AssetRules::statusRefusal($user, $currentStatus, $status, false, $asset);

                if ($refusal !== null) {
                    $errors[] = $refusal;
                } else {
                    $statusTo = $status;
                }
            }
        }

        // What would be saved: on an update only the fields that differ (a blank keeps the stored value).
        $fields = [];

        if ($errors === []) {
            if ($isNew) {
                $fields = [
                    'name' => $name,
                    'asset_category_id' => $category->id,
                    'serial_number' => $serial === '' ? null : $serial,
                    'purchase_date' => $purchase,
                    'cost' => $cost,
                    'condition' => $condition,
                ];
            } else {
                /** @var Asset $asset */
                $wanted = [
                    'name' => $name,
                    'asset_category_id' => $category->id,
                    ...($serial === '' ? [] : ['serial_number' => $serial]),
                    ...($purchase === null ? [] : ['purchase_date' => $purchase]),
                    ...($cost === null ? [] : ['cost' => $cost]),
                    ...($condition === null ? [] : ['condition' => $condition]),
                ];
                $stored = [
                    'name' => $asset->name,
                    'asset_category_id' => $asset->asset_category_id,
                    'serial_number' => $asset->serial_number,
                    'purchase_date' => $asset->purchase_date?->format('Y-m-d'),
                    'cost' => $asset->cost,
                    'condition' => $asset->condition,
                ];

                foreach ($wanted as $field => $value) {
                    if ($stored[$field] !== $value) {
                        $fields[$field] = $value;
                    }
                }
            }
        }

        $action = $isNew ? 'create' : ($fields === [] && $statusTo === null && ! $assign ? 'none' : 'update');

        return [
            'line' => $row['line'],
            'display' => [
                'code' => $asset->asset_code ?? $codeText,
                'name' => mb_strimwidth($name, 0, 80, '…'),
                'category' => $category->name ?? $categoryText,
                'serial' => mb_strimwidth($serial, 0, 60, '…'),
                'purchase_date' => $purchase ?? $purchaseText,
                'cost' => $cost !== null ? Money::format($cost) : $costText,
                'condition' => $condition !== null ? self::label($condition) : $conditionText,
                'status' => $status !== null ? self::label($status) : $statusText,
                'holder' => $holder !== null ? $holder->full_name : '',
                'holder_code' => $holder->employee_code ?? $holderText,
                'assigned_on' => $assignedOn ?? $assignedText,
            ],
            'errors' => $errors,
            'warnings' => $warnings,
            'action' => $action,
            'asset' => $asset,
            'serial_key' => $serialKey,
            'data' => $errors !== [] ? null : [
                'asset_id' => $asset?->id,
                'fields' => $fields,
                'status' => $statusTo,
                'employee_id' => $assign ? $holder->id : null,
                'assigned_on' => $assign ? $assignedOn : null,
                // What the run locks: every employee named, the category, the asset.
                'holder_id' => $holder?->id,
                'category_id' => $category->id,
            ],
        ];
    }
}
