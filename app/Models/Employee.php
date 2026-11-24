<?php

namespace App\Models;

use App\Support\ListQuery;
use Database\Factories\EmployeeFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * One person on the payroll. Who may see or change which part of a record is decided in one place,
 * App\Support\EmployeeAccess; never expose a model to a page without going through it.
 *
 * @property int $id
 * @property string $employee_code
 * @property string|null $device_user_id
 * @property string $first_name
 * @property string $last_name
 * @property string|null $photo_path
 * @property string $email
 * @property string|null $phone
 * @property Carbon|null $dob
 * @property string|null $gender
 * @property string|null $cnic
 * @property int $department_id
 * @property int $designation_id
 * @property int|null $manager_id
 * @property Carbon $joining_date
 * @property Carbon|null $probation_end_date
 * @property string $employment_type
 * @property string $status
 * @property Carbon|null $last_working_day
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read string $full_name
 */
class Employee extends Model
{
    /** @use HasFactory<EmployeeFactory> */
    use HasFactory, LogsActivity;

    protected $fillable = [
        'employee_code', 'device_user_id', 'first_name', 'last_name', 'photo_path', 'email', 'phone', 'dob', 'gender',
        'cnic', 'department_id', 'designation_id', 'manager_id', 'joining_date', 'probation_end_date',
        'employment_type', 'status', 'last_working_day',
    ];

    /**
     * Allow-list. `cnic` and `dob` are listed so a change is noticed, but their values are never stored:
     * config('hrm.audit.masked_attributes') makes the audit log record "[changed]" instead. The photo path is
     * not listed (a stored file name says nothing a reader needs).
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('employees')
            ->logOnly([
                'employee_code', 'device_user_id', 'first_name', 'last_name', 'email', 'phone', 'dob', 'gender', 'cnic',
                'department_id', 'designation_id', 'manager_id', 'joining_date', 'probation_end_date',
                'employment_type', 'status', 'last_working_day',
            ])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->setDescriptionForEvent(fn (string $event) => "Employee {$event}");
    }

    /** Last known name, stored with each entry so it survives deletion. */
    public function auditLabel(): string
    {
        return $this->full_name;
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'dob' => 'date:Y-m-d',
            'joining_date' => 'date:Y-m-d',
            'probation_end_date' => 'date:Y-m-d',
            'last_working_day' => 'date:Y-m-d',
        ];
    }

    /** @return Attribute<string, never> */
    protected function fullName(): Attribute
    {
        return Attribute::get(fn () => trim("{$this->first_name} {$this->last_name}"));
    }

    /** @return BelongsTo<Department, $this> */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /** @return BelongsTo<Designation, $this> */
    public function designation(): BelongsTo
    {
        return $this->belongsTo(Designation::class);
    }

    /** @return BelongsTo<Employee, $this> */
    public function manager(): BelongsTo
    {
        return $this->belongsTo(self::class, 'manager_id');
    }

    /**
     * Direct reports only.
     *
     * @return HasMany<Employee, $this>
     */
    public function reports(): HasMany
    {
        return $this->hasMany(self::class, 'manager_id');
    }

    /** @return HasOne<EmployeeContact, $this> */
    public function contact(): HasOne
    {
        return $this->hasOne(EmployeeContact::class);
    }

    /** @return HasOne<EmployeeBankAccount, $this> */
    public function bankAccount(): HasOne
    {
        return $this->hasOne(EmployeeBankAccount::class);
    }

    /** @return HasMany<EmployeeDocument, $this> */
    public function documents(): HasMany
    {
        return $this->hasMany(EmployeeDocument::class);
    }

    /**
     * The login account of this person, if they have one.
     *
     * @return HasOne<User, $this>
     */
    public function user(): HasOne
    {
        return $this->hasOne(User::class);
    }

    /**
     * @param  Builder<Employee>  $query
     * @return Builder<Employee>
     */
    public function scopeStatus(Builder $query, string $status): Builder
    {
        return $query->where('status', $status);
    }

    /**
     * Case-insensitive "contains" on first name, last name, full name, code and email; wildcards in the term are literal.
     *
     * @param  Builder<Employee>  $query
     * @return Builder<Employee>
     */
    public function scopeSearch(Builder $query, string $term): Builder
    {
        $term = trim($term);

        if ($term === '') {
            return $query;
        }

        $pattern = '%'.ListQuery::escapeLike($term).'%';

        return $query->where(function (Builder $w) use ($pattern) {
            foreach (['first_name', 'last_name', 'employee_code', 'email'] as $column) {
                $w->orWhereRaw("LOWER({$column}) LIKE ? ESCAPE '!'", [$pattern]);
            }
            // "||" is string concatenation on SQLite but a logical OR on MariaDB, so the full name is built per driver.
            $fullName = $w->getModel()->getConnection()->getDriverName() === 'sqlite'
                ? "first_name || ' ' || last_name"
                : "CONCAT(first_name, ' ', last_name)";
            $w->orWhereRaw("LOWER({$fullName}) LIKE ? ESCAPE '!'", [$pattern]);
        });
    }
}
