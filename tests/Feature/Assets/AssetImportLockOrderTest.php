<?php

use App\Models\Asset;
use App\Models\AssetCategory;
use App\Models\Employee;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\MySqlGrammar;
use Illuminate\Database\Query\Grammars\SQLiteGrammar;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

// The import run's statement order and locks. SQLite drops the lock clause, so while the run executes the connection's
// query grammar is swapped for one that writes the lock as a trailing SQL comment ("/* for update */", "/* shared */"),
// which SQLite ignores: the recorded SQL then shows the run's own queries in order and which of them lock (the same
// technique as AssignmentLockOrderTest). MySqlGrammar shows the same queries compile to FOR UPDATE / LOCK IN SHARE MODE.
// Order: every employee of the file FOR UPDATE ascending, then the categories shared, then the existing assets FOR
// UPDATE ascending, then the writes; never an asset row locked before an employee row; asset_assignments never locked.

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

/**
 * The SQL the run sends from its transaction's first BEGIN on, with bindings, leaving out role / permission lookups,
 * savepoints and activity log writes.
 *
 * @return list<array{sql: string, bindings: array<int, mixed>}>
 */
function ailRunStatements(Closure $run): array
{
    $connection = DB::connection();
    $original = $connection->getQueryGrammar();
    $connection->setQueryGrammar(new class($connection) extends SQLiteGrammar
    {
        protected function compileLock(Builder $query, $value)
        {
            return match (true) {
                $value === true => '/* for update */',
                $value === false => '/* shared */',
                default => '',
            };
        }
    });

    $log = [];
    Event::listen(TransactionBeginning::class, function () use (&$log): void {
        $log[] = 'begin';
    });
    DB::listen(function ($query) use (&$log): void {
        $log[] = ['sql' => $query->sql, 'bindings' => $query->bindings];
    });

    try {
        $run();
    } finally {
        $connection->setQueryGrammar($original);
    }

    $from = array_search('begin', $log, true);
    expect($from)->not->toBeFalse();

    return array_values(array_filter(array_slice($log, (int) $from), fn ($entry) => is_array($entry)
        && ! str_contains($entry['sql'], 'savepoint') && ! str_contains($entry['sql'], 'roles')
        && ! str_contains($entry['sql'], 'permissions') && ! str_contains($entry['sql'], 'activity_log')));
}

/** "1, 2, 3": the ids ascending, as whereKey() writes integer keys into the SQL. */
function ailIds(array $ids): string
{
    sort($ids);

    return implode(', ', $ids);
}

function ailIndex(array $statements, Closure $match): int
{
    foreach ($statements as $i => $entry) {
        if ($match($entry['sql'])) {
            return $i;
        }
    }

    return -1;
}

it('compiles the run\'s three lock queries to FOR UPDATE and LOCK IN SHARE MODE on MariaDB', function () {
    $grammar = new MySqlGrammar(DB::connection());

    expect($grammar->compileSelect(Employee::query()->whereKey([2, 1])->orderBy('id')->lockForUpdate()->toBase()))
        ->toBe('select * from `employees` where `employees`.`id` in (2, 1) order by `id` asc for update')
        ->and($grammar->compileSelect(AssetCategory::query()->whereKey([1])->orderBy('id')->sharedLock()->toBase()))
        ->toEndWith('order by `id` asc lock in share mode')
        ->and($grammar->compileSelect(Asset::query()->whereKey([1])->orderBy('id')->lockForUpdate()->toBase()))
        ->toEndWith('order by `id` asc for update');
});

it('with new rows, locks the employees ascending, then the categories, then every asset row ascending, before writing', function () {
    $hr = userWithRole('hr');
    // Ids deliberately out of file order.
    $third = aaPerson(['employee_code' => 'LD-0103']);
    $first = aaPerson(['employee_code' => 'LD-0101']);
    $second = aaPerson(['employee_code' => 'LD-0102']);
    $assetB = assetRow(['asset_code' => 'AST-0001', 'serial_number' => 'B', 'name' => 'B']);
    $assetA = assetRow(['asset_code' => 'AST-0002', 'serial_number' => 'A', 'name' => 'A', 'category' => 'Phone']);
    $held = assignedAsset($second, ['asset_code' => 'AST-0003', 'serial_number' => 'H', 'name' => 'H', 'assigned_on' => '2026-11-10']);

    $preview = ariPreview($hr, ariXlsx([
        ['AST-0002', 'A renamed', 'Phone', null, null, null, null, null, 'LD-0101', '2026-11-25'],
        [null, 'New one', 'Monitor', 'NEW', null, null, 'Good', null, 'LD-0103', '2026-11-25'],
        ['AST-0001', 'B', 'Laptop', null, null, 5000, null, null, null, null],
        ['AST-0003', 'H', 'Laptop', null, null, null, null, 'Assigned', 'LD-0102', '2026-11-10'],
    ]));
    expect($preview['totals']['errors'])->toBe(0);

    $statements = ailRunStatements(fn () => ariRun($hr, $preview['token'])->assertSessionHas('success'));
    $sql = array_column($statements, 'sql');

    $employees = ailIndex($statements, fn ($s) => str_contains($s, 'from "employees"') && str_ends_with($s, '/* for update */'));
    $categories = ailIndex($statements, fn ($s) => str_contains($s, 'from "asset_categories"') && str_ends_with($s, '/* shared */'));
    $assets = ailIndex($statements, fn ($s) => str_contains($s, 'from "assets"') && str_ends_with($s, '/* for update */'));
    $firstWrite = ailIndex($statements, fn ($s) => str_starts_with($s, 'insert') || str_starts_with($s, 'update'));

    // The first three statements of the transaction are the three locks, in this order.
    expect($employees)->toBe(0)
        ->and($categories)->toBe(1)
        ->and($assets)->toBe(2)
        ->and($firstWrite)->toBeGreaterThan($assets)
        ->and($sql[0])->toContain('where "employees"."id" in')->toContain('order by "id" asc')
        ->and($sql[0])->toContain('in ('.ailIds([$first->id, $second->id, $third->id]).')')
        ->and($sql[1])->toContain('order by "id" asc')
        ->and($sql[1])->toContain('in ('.ailIds([assetCategory('Laptop')->id, assetCategory('Phone')->id, assetCategory('Monitor')->id]).')')
        // A file with a new row locks the whole register once, ascending, with no id filter: the new row's code scan
        // (AssetCode::next) then finds every row already locked by this transaction.
        ->and($sql[2])->toContain('order by "id" asc')->not->toContain('"assets"."id" in')
        ->and($sql[2])->not->toContain('where');
    expect(array_filter($sql, fn ($s) => str_contains($s, 'from "assets"') && str_contains($s, '/*')))->toHaveCount(2);

    // Never an asset row locked before an employee row, and never an employee row locked after the assets.
    $assetLockBefore = array_filter(array_slice($sql, 0, $employees), fn ($s) => str_contains($s, 'from "assets"') && str_contains($s, '/*'));
    $employeeLockAfter = array_filter(array_slice($sql, $assets + 1), fn ($s) => str_contains($s, 'from "employees"') && str_contains($s, '/*'));
    expect($assetLockBefore)->toBeEmpty()
        ->and($employeeLockAfter)->toBeEmpty()
        // asset_assignments is read plainly only: a locking read would take gap locks (see AssignAsset).
        ->and(array_filter($sql, fn ($s) => str_contains($s, '"asset_assignments"') && str_contains($s, '/*')))->toBeEmpty()
        ->and(array_filter($sql, fn ($s) => str_starts_with($s, 'insert into "asset_assignments"')))->toHaveCount(2);

    assertAssetInvariant();
});

it('without a new row, locks only the own assets of the file, ascending', function () {
    $hr = userWithRole('hr');
    $sara = aaPerson(['employee_code' => 'LD-0101']);
    $assetB = assetRow(['asset_code' => 'AST-0001', 'serial_number' => 'B', 'name' => 'B']);
    $assetA = assetRow(['asset_code' => 'AST-0002', 'serial_number' => 'A', 'name' => 'A']);
    assetRow(['asset_code' => 'AST-0003', 'serial_number' => 'C', 'name' => 'C']);

    $preview = ariPreview($hr, ariXlsx([
        ['AST-0002', 'A', 'Laptop', null, null, null, null, null, 'LD-0101', '2026-11-25'],
        ['AST-0001', 'B', 'Laptop', null, null, 5000, null, null, null, null],
    ]));
    expect($preview['totals']['errors'])->toBe(0);

    $sql = array_column(ailRunStatements(fn () => ariRun($hr, $preview['token'])->assertSessionHas('success')), 'sql');

    expect($sql[0])->toContain('from "employees"')->toContain('in ('.$sara->id.')')
        ->and($sql[2])->toContain('from "assets" where "assets"."id" in')->toContain('in ('.ailIds([$assetA->id, $assetB->id]).')')
        ->toEndWith('/* for update */');
});

it('takes no lock on employees or assets before its own transaction', function () {
    $hr = userWithRole('hr');
    aaPerson(['employee_code' => 'LD-0101']);
    assetRow(['asset_code' => 'AST-0001', 'serial_number' => 'B', 'name' => 'B']);

    $preview = ariPreview($hr, ariXlsx([['AST-0001', 'B', 'Laptop', null, null, null, null, null, 'LD-0101', '2026-11-25']]));

    $connection = DB::connection();
    $original = $connection->getQueryGrammar();
    $connection->setQueryGrammar(new class($connection) extends SQLiteGrammar
    {
        protected function compileLock(Builder $query, $value)
        {
            return $value === true ? '/* for update */' : ($value === false ? '/* shared */' : '');
        }
    });

    $log = [];
    Event::listen(TransactionBeginning::class, function () use (&$log): void {
        $log[] = 'begin';
    });
    DB::listen(function ($query) use (&$log): void {
        $log[] = $query->sql;
    });

    try {
        ariRun($hr, $preview['token'])->assertSessionHas('success');
    } finally {
        $connection->setQueryGrammar($original);
    }

    $before = array_slice($log, 0, (int) array_search('begin', $log, true));
    expect(array_filter($before, fn ($s) => is_string($s) && str_contains($s, '/*')))->toBeEmpty();
});
