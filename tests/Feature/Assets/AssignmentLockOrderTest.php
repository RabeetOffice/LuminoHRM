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
use Illuminate\Database\Query\Grammars\MySqlGrammar;
use Illuminate\Support\Facades\DB;

// Statement order. SQLite drops the lock clause, so the recorded SQL shows the order of the reads, and the lock itself is
// checked by compiling the same query with MySqlGrammar (what MariaDB receives). Lock order: employee row, then asset row;
// never an asset row and then an employee row.

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
        $statements = [];
        DB::listen(function ($query) use (&$statements) {
            $statements[] = $query->sql;
        });
        $run();

        return array_values(array_filter($statements, fn (string $sql) => ! str_contains($sql, 'savepoint')
            && ! str_contains($sql, 'roles') && ! str_contains($sql, 'permissions') && ! str_contains($sql, 'activity_log')));
    }

    /** What MariaDB receives for a lock by id on $model's table. */
    function loCompiledLock(string $model, int $id): string
    {
        return (new MySqlGrammar(DB::connection()))->compileSelect($model::query()->whereKey($id)->lockForUpdate()->toBase());
    }

    /** Index of the first statement that selects from $table by its id, or -1. */
    function loFirstSelectById(array $statements, string $table): int
    {
        foreach ($statements as $i => $sql) {
            if (str_starts_with($sql, 'select') && str_contains($sql, "from \"{$table}\" where \"{$table}\".\"id\" = ?")) {
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

it('assign: the employee by id first, the asset by id second, nothing about assets before them', function () {
    $hr = userWithRole('hr');
    $employee = Employee::query()->findOrFail(aaPerson()->id);
    $asset = Asset::query()->findOrFail(assetRow()->id);

    $statements = loStatements(fn () => app(AssignAsset::class)->handle($hr, $asset, $employee, '2026-11-26', null));

    expect($statements[0])->toStartWith('select')->toContain('from "employees" where "employees"."id" = ?')
        ->and($statements[1])->toStartWith('select')->toContain('from "assets" where "assets"."id" = ?');

    $later = array_slice($statements, 2);
    expect(array_filter($later, fn ($sql) => str_contains($sql, 'insert into "asset_assignments"')))->not->toBeEmpty()
        ->and(array_filter($later, fn ($sql) => str_contains($sql, 'update "assets"')))->not->toBeEmpty();
});

it('return: the assignment read plainly, then the employee, then the asset, then the assignment again', function () {
    $hr = userWithRole('hr');
    $held = assignedAsset(aaPerson(), ['assigned_on' => '2026-11-10']);
    $assignment = AssetAssignment::query()->where('asset_id', $held->id)->sole();
    $fresh = AssetAssignment::query()->findOrFail($assignment->id);

    $statements = loStatements(fn () => app(ReturnAsset::class)->handle($hr, $fresh, '2026-11-26', 'good', 'available', null));

    expect($statements[0])->toStartWith('select')->toContain('from "asset_assignments" where "asset_assignments"."id" = ?')
        ->and($statements[1])->toStartWith('select')->toContain('from "employees" where "employees"."id" = ?')
        ->and($statements[2])->toStartWith('select')->toContain('from "assets" where "assets"."id" = ?')
        ->and($statements[3])->toStartWith('select')->toContain('from "asset_assignments" where "asset_assignments"."id" = ?');
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

    $employee = loFirstSelectById($statements, 'employees');
    $asset = loFirstSelectById($statements, 'assets');

    expect($employee)->toBeGreaterThanOrEqual(0)
        ->and($asset)->toBeGreaterThan($employee);
})->with(['assign', 'assign refused (held)', 'return']);

it('the note edit locks only its assignment row, with no employee or asset lock', function () {
    $hr = userWithRole('hr');
    $held = assignedAsset(aaPerson());
    $assignment = AssetAssignment::query()->where('asset_id', $held->id)->sole();
    $fresh = AssetAssignment::query()->findOrFail($assignment->id);

    $statements = loStatements(fn () => app(EditAssignmentNote::class)->handle($hr, $fresh, 'New note', null));

    expect($statements[0])->toStartWith('select')->toContain('from "asset_assignments" where "asset_assignments"."id" = ?')
        ->and(loFirstSelectById(array_slice($statements, 0, 2), 'employees'))->toBe(-1)
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

    expect($statements[0])->toStartWith('select')->toContain('from "assets" where "assets"."id" = ?')
        ->and(array_filter($statements, fn ($sql) => str_contains($sql, 'from "employees"')))->toBeEmpty();
})->with(['edit', 'status', 'delete']);
