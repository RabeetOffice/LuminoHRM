<?php

namespace App\Actions\Performance;

use App\Models\Designation;
use App\Models\Employee;
use App\Models\Kpi;
use App\Models\ReviewScore;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Names;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Create, change and delete KPIs (Performance > KPIs). One transaction each, audited in it (area performance). Update and
 * delete lock the KPI row first.
 *
 * `$data`: name, description (optional), weight, is_active, target ('designation' or 'employee') and the matching
 * designation_id or employee_id. Refusals are ValidationExceptions on the field named in the message constants below.
 * Editing a KPI never touches reviews that already copied it.
 */
class SaveKpi
{
    public const WEIGHT_MESSAGE = 'Weight must be a whole number from 1 to 100.';

    public const TARGET_NONE = 'Choose a designation or an employee.';

    public const TARGET_BOTH = 'Choose a designation or an employee, not both.';

    public static function employeeMessage(string $name): string
    {
        return "{$name} cannot be chosen: only active or probation employees can have KPIs.";
    }

    public static function inUseMessage(int $count): string
    {
        return "{$count} review(s) use this KPI, so it cannot be deleted. Deactivate it instead.";
    }

    /** @param  array<string, mixed>  $data */
    public function create(User $by, array $data): Kpi
    {
        $clean = $this->clean($data);

        return DB::transaction(function () use ($by, $clean): Kpi {
            $target = $this->resolveTarget($clean, null);

            $kpi = Kpi::query()->create([
                'name' => $clean['name'],
                'description' => $clean['description'],
                'weight' => $clean['weight'],
                'is_active' => $clean['is_active'],
                'designation_id' => $target['designation_id'],
                'employee_id' => $target['employee_id'],
            ]);

            Audit::record('performance', 'KPI created', $kpi, [
                'attributes' => [
                    'name' => $kpi->name,
                    'description' => $kpi->description,
                    'weight' => $kpi->weight,
                    'active' => $kpi->is_active,
                    'target_type' => $target['type'],
                    'target' => $target['name'],
                ],
            ], $by);

            return $kpi;
        }, 3);
    }

    /** @param  array<string, mixed>  $data */
    public function update(User $by, Kpi $kpi, array $data): Kpi
    {
        $clean = $this->clean($data);

        return DB::transaction(function () use ($by, $kpi, $clean): Kpi {
            $current = Kpi::query()->whereKey($kpi->getKey())->lockForUpdate()->firstOrFail();
            $before = $this->targetOf($current);

            $targetChanged = $this->targetChanged($current, $clean);
            // The "active or probation" rule applies to a newly chosen person only, so an old KPI of someone who has since left stays editable.
            $target = $this->resolveTarget($clean, $targetChanged ? null : $current);

            $old = ['name' => $current->name, 'description' => $current->description, 'weight' => $current->weight, 'active' => $current->is_active];

            $current->fill([
                'name' => $clean['name'],
                'description' => $clean['description'],
                'weight' => $clean['weight'],
                'is_active' => $clean['is_active'],
                'designation_id' => $target['designation_id'],
                'employee_id' => $target['employee_id'],
            ]);

            $dirty = $current->getDirty();

            if ($dirty === []) {
                return $current;
            }

            $deactivated = array_key_exists('is_active', $dirty) && $old['active'] === true && $current->is_active === false;

            $current->save();

            $oldValues = [];
            $newValues = [];

            foreach (['name', 'description', 'weight'] as $field) {
                if (array_key_exists($field, $dirty)) {
                    $oldValues[$field] = $old[$field];
                    $newValues[$field] = $current->{$field};
                }
            }

            // Turning a KPI back on is an ordinary update; turning it off has its own entry.
            if (array_key_exists('is_active', $dirty) && ! $deactivated) {
                $oldValues['active'] = $old['active'];
                $newValues['active'] = $current->is_active;
            }

            if ($targetChanged) {
                $oldValues += ['target_type' => $before['type'], 'target' => $before['name']];
                $newValues += ['target_type' => $target['type'], 'target' => $target['name']];
            }

            if ($newValues !== []) {
                Audit::record('performance', 'KPI updated', $current, ['old' => $oldValues, 'attributes' => $newValues], $by);
            }

            if ($deactivated) {
                Audit::record('performance', 'KPI deactivated', $current, [
                    'old' => ['active' => true],
                    'attributes' => ['active' => false],
                ], $by);
            }

            return $current;
        }, 3);
    }

    public function delete(User $by, Kpi $kpi): void
    {
        try {
            DB::transaction(function () use ($by, $kpi): void {
                $current = Kpi::query()->whereKey($kpi->getKey())->lockForUpdate()->firstOrFail();

                $used = ReviewScore::query()->where('kpi_id', $current->id)->count();

                if ($used > 0) {
                    throw ValidationException::withMessages(['kpi' => self::inUseMessage($used)]);
                }

                $target = $this->targetOf($current);
                $old = [
                    'name' => $current->name,
                    'description' => $current->description,
                    'weight' => $current->weight,
                    'active' => $current->is_active,
                    'target_type' => $target['type'],
                    'target' => $target['name'],
                ];

                $current->delete();

                Audit::record('performance', 'KPI deleted', $current, ['old' => $old], $by);
            }, 3);
        } catch (QueryException $e) {
            // Only a foreign key refusal (SQLSTATE 23000) is the friendly case; anything else is a real fault.
            if ($e->getCode() !== '23000') {
                throw $e;
            }

            // A review copied this KPI after the count above and the restrict foreign key refused (the backstop).
            $used = ReviewScore::query()->where('kpi_id', $kpi->getKey())->count();

            throw ValidationException::withMessages(['kpi' => self::inUseMessage(max($used, 1))]);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{name: string, description: ?string, weight: int, is_active: bool, target: mixed, designation_id: ?int, employee_id: ?int}
     */
    private function clean(array $data): array
    {
        $weight = $data['weight'] ?? null;

        if (is_string($weight) && preg_match('/^\d{1,3}$/', $weight) === 1) {
            $weight = (int) $weight;
        }

        if (! is_int($weight) || $weight < 1 || $weight > 100) {
            throw ValidationException::withMessages(['weight' => self::WEIGHT_MESSAGE]);
        }

        $description = Names::normalise($data['description'] ?? null);

        return [
            'name' => (string) Names::normalise($data['name'] ?? ''),
            'description' => is_string($description) && $description !== '' ? $description : null,
            'weight' => $weight,
            'is_active' => (bool) ($data['is_active'] ?? true),
            'target' => $data['target'] ?? null,
            'designation_id' => $this->id($data['designation_id'] ?? null),
            'employee_id' => $this->id($data['employee_id'] ?? null),
        ];
    }

    private function id(mixed $value): ?int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }

        return is_string($value) && ctype_digit($value) && (int) $value > 0 ? (int) $value : null;
    }

    /**
     * Whether the update moves the KPI to a different designation or person.
     *
     * @param  array{target: mixed, designation_id: ?int, employee_id: ?int}  $clean
     */
    private function targetChanged(Kpi $current, array $clean): bool
    {
        return $clean['designation_id'] !== $current->designation_id || $clean['employee_id'] !== $current->employee_id;
    }

    /**
     * Judges the chosen target. With $existing (an unchanged target) the employee-status rule is skipped.
     *
     * @param  array{target: mixed, designation_id: ?int, employee_id: ?int}  $clean
     * @return array{type: string, name: string, designation_id: ?int, employee_id: ?int}
     */
    private function resolveTarget(array $clean, ?Kpi $existing): array
    {
        $designationId = $clean['designation_id'];
        $employeeId = $clean['employee_id'];

        if ($designationId !== null && $employeeId !== null) {
            throw ValidationException::withMessages(['target' => self::TARGET_BOTH]);
        }

        $type = $clean['target'];
        $wanted = $type === 'designation' ? $designationId : ($type === 'employee' ? $employeeId : null);

        if ($wanted === null) {
            throw ValidationException::withMessages(['target' => self::TARGET_NONE]);
        }

        if ($type === 'designation') {
            $designation = Designation::query()->find($designationId);

            if ($designation === null) {
                throw ValidationException::withMessages(['target' => self::TARGET_NONE]);
            }

            return ['type' => 'designation', 'name' => $designation->name, 'designation_id' => $designation->id, 'employee_id' => null];
        }

        $employee = Employee::query()->find($employeeId);

        if ($employee === null) {
            throw ValidationException::withMessages(['target' => self::TARGET_NONE]);
        }

        if ($existing === null && ! in_array($employee->status, ['active', 'probation'], true)) {
            throw ValidationException::withMessages(['employee_id' => self::employeeMessage($employee->full_name)]);
        }

        return ['type' => 'employee', 'name' => $employee->full_name, 'designation_id' => null, 'employee_id' => $employee->id];
    }

    /** @return array{type: string, name: string} */
    private function targetOf(Kpi $kpi): array
    {
        if ($kpi->designation_id !== null) {
            return ['type' => 'designation', 'name' => (string) Designation::query()->whereKey($kpi->designation_id)->value('name')];
        }

        // The foreign key guarantees the person exists.
        return ['type' => 'employee', 'name' => Employee::query()->findOrFail($kpi->employee_id)->full_name];
    }
}
