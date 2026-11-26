<?php

namespace App\Models;

use App\Casts\NaiveDate;
use Carbon\CarbonImmutable;
use Database\Factories\AssetAssignmentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * One hand-over of an asset to an employee. Open while returned_on is null.
 *
 * Guards (whoever writes the row):
 *  - open_asset_id is set on every save: the asset id while the row is open, NULL once returned. Its unique index is what
 *    stops two open rows for one asset, so the model never lets it drift from returned_on.
 *  - a returned row is history: only note and return_note may change (read from the stored row, so a stale model cannot
 *    slip a change through). An open row may not change its asset, employee or assigned_on.
 *  - a row is never deleted.
 *
 * @property int $id
 * @property int $asset_id
 * @property int $employee_id
 * @property CarbonImmutable $assigned_on
 * @property CarbonImmutable|null $returned_on
 * @property string $condition_out
 * @property string|null $return_condition
 * @property string|null $note
 * @property string|null $return_note
 * @property int|null $open_asset_id
 * @property int|null $assigned_by
 * @property int|null $returned_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class AssetAssignment extends Model
{
    /** @use HasFactory<AssetAssignmentFactory> */
    use HasFactory;

    /** The only columns that may change on a returned row. */
    private const EDITABLE_AFTER_RETURN = ['note', 'return_note', 'updated_at'];

    /** Columns an open row may not change. */
    private const FIXED_WHILE_OPEN = ['asset_id', 'employee_id', 'assigned_on'];

    protected $fillable = [
        'asset_id', 'employee_id', 'assigned_on', 'returned_on', 'condition_out', 'return_condition', 'note', 'return_note',
        'open_asset_id', 'assigned_by', 'returned_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'assigned_on' => NaiveDate::class,
            'returned_on' => NaiveDate::class,
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (AssetAssignment $assignment): void {
            $assignment->open_asset_id = $assignment->returned_on === null ? $assignment->asset_id : null;
        });

        static::updating(function (AssetAssignment $assignment): void {
            // A plain read: inside a transaction on MariaDB REPEATABLE READ it returns the transaction's snapshot, not
            // necessarily the latest row. Callers that decide on the row's state must re-read it FOR UPDATE first (as
            // ReturnAsset and EditAssignmentNote do); this guard is a backstop for history, not a concurrency check.
            $stored = static::query()->whereKey($assignment->getKey())->first();

            if ($stored === null) {
                return;
            }

            $dirty = array_keys($assignment->getDirty());

            if ($stored->returned_on !== null) {
                if (array_diff($dirty, self::EDITABLE_AFTER_RETURN) !== []) {
                    throw new LogicException('A returned assignment is history: only its notes can change.');
                }

                return;
            }

            if (array_intersect($dirty, self::FIXED_WHILE_OPEN) !== []) {
                throw new LogicException('An open assignment cannot change its asset, employee or date: return it and assign again.');
            }
        });

        static::deleting(function (): void {
            throw new LogicException('Assignment rows are never deleted.');
        });
    }

    /** @param  Builder<static>  $query */
    public function scopeOpen(Builder $query): void
    {
        $query->whereNull($query->qualifyColumn('returned_on'));
    }

    public function isOpen(): bool
    {
        return $this->returned_on === null;
    }

    /** @return BelongsTo<Asset, $this> */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
