<?php

use App\Actions\Assets\AssignAsset;
use App\Actions\Assets\ChangeAssetStatus;
use App\Actions\Assets\DeleteAsset;
use App\Actions\Assets\EditAssignmentNote;
use App\Actions\Assets\ReturnAsset;
use App\Actions\Assets\SaveAsset;
use App\Models\Asset;
use App\Models\AssetAssignment;
use App\Models\Employee;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\MySqlGrammar;
use Illuminate\Database\Query\Grammars\SQLiteGrammar;
use Illuminate\Support\Facades\DB;

// Statement order and locks. SQLite drops the lock clause, so while an action runs the connection's query grammar is swapped
// for one that writes the lock as a trailing SQL comment ("/* for update */", "/* shared */"), which SQLite ignores. The
// recorded SQL then shows both the order of the actions' own reads and which of them lock. MySqlGrammar shows the same lock
// compiles to FOR UPDATE on MariaDB. Lock order: employee row, then asset row; never an asset row and then an employee row.

require_once __DIR__.'/Support/assignment-helpers.php';

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-26 14:00:00', 'Asia/Karachi'));
});

if (! function_exists('loStatements')) {
    /**
     * The SQL $run sends, with the role/permission lookups, savepoints and activity log writes left out, each with its
     * bindings so a "by id" select can be told apart.
     *
     * @return list<string>
     */
    function loStatements(Closure $run): array
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

        $statements = [];
        DB::listen(function ($query) use (&$statements) {
            $statements[] = $query->sql;
        });

        try {
            $run();
        } finally {
            $connection->setQueryGrammar($original);
        }

        return array_values(array_filter($statements, fn (string $sql) => ! str_contains($sql, 'savepoint')
            && ! str_contains($sql, 'roles') && ! str_contains($sql, 'permissions') && ! str_contains($sql, 'activity_log')));
    }

    /** What MariaDB receives for a lock by id on $model's table. */
    function loCompiledLock(string $model, int $id): string
    {
        return (new MySqlGrammar(DB::connection()))->compileSelect($model::query()->whereKey($id)->lockForUpdate()->toBase());
    }

    /** Whether a recorded statement took a FOR UPDATE lock. */
    function loLocks(string $sql): bool
    {
        return str_ends_with($sql, '/* for update */');
    }

    /** Index of the first statement that selects from $table by its id (and locks it, when $locked), or -1. */
    function loFirstSelectById(array $statements, string $table, bool $locked = false): int
    {
        foreach ($statements as $i => $sql) {
            if (str_starts_with($sql, 'select') && str_contains($sql, "from \"{$table}\" where \"{$table}\".\"id\" = ?")
                && (! $locked || loLocks($sql))) {
                return $i;
            }
        }

        return -1;
    }
}

it('compiles the employee, asset and assignment locks to FOR UPDATE by id', function () {
    expect(loCompiledLock(Employee::class, 1))->toContain('from `employees` where `employees`.`id` = ?')->toEndWith('for update')
        ->and(loCompiledLock(Asset::class, 1))->toContain('from `assets` where `assets`.`id` = ?')->toEndWith('for update')
        ->and(loCompiledLock(AssetAssignment::class, 1))->toContain('from `asset_assignments` where `asset_assignments`.`id` = ?')->toEndWith('for update');
});

it('marks the locks in the recorded SQL, and restores the grammar afterwards', function () {
    $statements = loStatements(function () {
        Employee::query()->whereKey(1)->lockForUpdate()->first();
        Employee::query()->whereKey(1)->sharedLock()->first();
        Employee::query()->whereKey(1)->first();
    });

    expect($statements[0])->toEndWith('/* for update */')
        ->and($statements[1])->toEndWith('/* shared */')
        ->and(loLocks($statements[2]))->toBeFalse()
        ->and(Employee::query()->whereKey(1)->lockForUpdate()->toSql())->not->toContain('/*');
});

it('assign: the employee locked by id first, the asset locked by id second, nothing about assets before them', function () {
    $hr = userWithRole('hr');
    $employee = Employee::query()->findOrFail(aaPerson()->id);
    $asset = Asset::query()->findOrFail(assetRow()->id);

    $statements = loStatements(fn () => app(AssignAsset::class)->handle($hr, $asset, $employee, '2026-11-26', null));

    expect($statements[0])->toStartWith('select')->toContain('from "employees" where "employees"."id" = ?')->toEndWith('/* for update */')
        ->and($statements[1])->toStartWith('select')->toContain('from "assets" where "assets"."id" = ?')->toEndWith('/* for update */');

    $later = array_slice($statements, 2);
    expect(array_filter($later, fn ($sql) => str_contains($sql, 'insert into "asset_assignments"')))->not->toBeEmpty()
        ->and(array_filter($later, fn ($sql) => str_contains($sql, 'update "assets"')))->not->toBeEmpty()
        // No locking read of asset_assignments: its gap lock would deadlock two assigns of different new assets.
        ->and(array_filter($statements, fn ($sql) => str_contains($sql, '"asset_assignments"') && str_contains($sql, '/*')))->toBeEmpty();
});

it('assign refused because held: the open row and the holder are read plainly, after the two locks', function () {
    $held = assignedAsset(aaPerson(['first_name' => 'Ali']));
    $sara = Employee::query()->findOrFail(aaPerson()->id);
    $hr = userWithRole('hr');

    $statements = loStatements(fn () => rescue(fn () => app(AssignAsset::class)->handle($hr, $held, $sara, '2026-11-26', null), report: false));

    expect(loLocks($statements[0]))->toBeTrue()->and($statements[0])->toContain('from "employees"')
        ->and(loLocks($statements[1]))->toBeTrue()->and($statements[1])->toContain('from "assets"');

    $after = array_slice($statements, 2);
    expect(array_filter($after, fn ($sql) => str_contains($sql, 'from "asset_assignments"')))->not->toBeEmpty()
        ->and(array_filter($after, fn ($sql) => str_contains($sql, '/*')))->toBeEmpty();
});

it('return: the assignment read plainly, then the employee, then the asset, then the assignment again', function () {
    $hr = userWithRole('hr');
    $held = assignedAsset(aaPerson(), ['assigned_on' => '2026-11-10']);
    $assignment = AssetAssignment::query()->where('asset_id', $held->id)->sole();
    $fresh = AssetAssignment::query()->findOrFail($assignment->id);

    $statements = loStatements(fn () => app(ReturnAsset::class)->handle($hr, $fresh, '2026-11-26', 'good', 'available', null));

    expect($statements[0])->toStartWith('select')->toContain('from "asset_assignments" where "asset_assignments"."id" = ?')
        ->and(loLocks($statements[0]))->toBeFalse()
        ->and($statements[1])->toStartWith('select')->toContain('from "employees" where "employees"."id" = ?')->toEndWith('/* for update */')
        ->and($statements[2])->toStartWith('select')->toContain('from "assets" where "assets"."id" = ?')->toEndWith('/* for update */')
        ->and($statements[3])->toStartWith('select')->toContain('from "asset_assignments" where "asset_assignments"."id" = ?')->toEndWith('/* for update */');
});

it('never takes an asset row before an employee row on the assign and return paths', function (string $path) {
    $hr = userWithRole('admin');
    $sara = aaPerson();
    $free = assetRow();
    $held = assignedAsset(aaPerson(['first_name' => 'Ali']), ['assigned_on' => '2026-11-10']);
    $assignment = AssetAssignment::query()->where('asset_id', $held->id)->sole();

    $statements = loStatements(fn () => match ($path) {
        'assign' => app(AssignAsset::class)->handle($hr, $free, $sara, '2026-11-26', null),
        'assign refused (held)' => rescue(fn () => app(AssignAsset::class)->handle($hr, $held, $sara, '2026-11-26', null), report: false),
        'return' => app(ReturnAsset::class)->handle($hr, $assignment, '2026-11-26', 'good', 'retired', null),
    });

    $employee = loFirstSelectById($statements, 'employees', locked: true);
    $asset = loFirstSelectById($statements, 'assets', locked: true);
    $employeeLockedLater = array_filter(array_slice($statements, $asset + 1), fn ($sql) => str_contains($sql, 'from "employees"') && loLocks($sql));

    expect($employee)->toBeGreaterThanOrEqual(0)
        ->and($asset)->toBeGreaterThan($employee)
        ->and($employeeLockedLater)->toBeEmpty();
})->with(['assign', 'assign refused (held)', 'return']);

it('the note edit locks only its assignment row, with no employee or asset lock', function () {
    $hr = userWithRole('hr');
    $held = assignedAsset(aaPerson());
    $assignment = AssetAssignment::query()->where('asset_id', $held->id)->sole();
    $fresh = AssetAssignment::query()->findOrFail($assignment->id);

    $statements = loStatements(fn () => app(EditAssignmentNote::class)->handle($hr, $fresh, 'New note', null));

    expect($statements[0])->toStartWith('select')->toContain('from "asset_assignments" where "asset_assignments"."id" = ?')->toEndWith('/* for update */')
        ->and(array_filter($statements, fn ($sql) => str_contains($sql, 'from "employees"') && loLocks($sql)))->toBeEmpty()
        ->and(loFirstSelectById($statements, 'assets'))->toBe(-1);
});

it('the asset edit, status change and delete paths lock the asset row first and never an employee row', function (string $path) {
    $admin = userWithRole('admin');
    $asset = assetRow(['condition' => 'good']);
    $fresh = Asset::query()->findOrFail($asset->id);

    $statements = loStatements(fn () => match ($path) {
        'edit' => app(SaveAsset::class)->update($admin, $fresh, [
            'name' => 'Renamed', 'asset_category_id' => $asset->asset_category_id, 'serial_number' => $asset->serial_number,
            'purchase_date' => null, 'cost' => null, 'condition' => 'fair', 'notes' => null,
        ]),
        'status' => app(ChangeAssetStatus::class)->handle($admin, $fresh, 'in_repair'),
        'delete' => app(DeleteAsset::class)->handle($admin, $fresh),
    });

    expect($statements[0])->toStartWith('select')->toContain('from "assets" where "assets"."id" = ?')->toEndWith('/* for update */')
        ->and(array_filter($statements, fn ($sql) => str_contains($sql, 'from "employees"')))->toBeEmpty();
})->with(['edit', 'status', 'delete']);
