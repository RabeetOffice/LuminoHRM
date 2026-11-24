<?php

namespace App\Models;

use App\Casts\NaiveDate;
use App\Models\Concerns\HasNaiveDateScopes;
use Carbon\CarbonImmutable;
use Database\Factories\HolidayFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * A company holiday (one per date). date is stored as "Y-m-d" (App\Casts\NaiveDate). Audited (area attendance).
 *
 * @property int $id
 * @property string $name
 * @property CarbonImmutable $date
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Holiday extends Model
{
    /** @use HasFactory<HolidayFactory> */
    use HasFactory, LogsActivity;

    use HasNaiveDateScopes;

    protected $fillable = ['name', 'date'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['date' => NaiveDate::class];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('attendance')
            ->logOnly(['name', 'date'])
            ->useAttributeRawValues(['date']) // the stored "Y-m-d", not a serialised instant
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->setDescriptionForEvent(fn (string $event) => "Holiday {$event}");
    }

    public function auditLabel(): string
    {
        return $this->name;
    }
}
