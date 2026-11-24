<?php

namespace App\Models;

use Database\Factories\EmployeeDocumentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * A file on the private disk. The stored path is never shown to a page: files are served by an authorised route.
 *
 * @property int $id
 * @property int $employee_id
 * @property string $type
 * @property string $title
 * @property string $file_path
 * @property string $original_name
 * @property string $mime
 * @property int $size_bytes
 * @property Carbon|null $expiry_date
 * @property int|null $uploaded_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class EmployeeDocument extends Model
{
    /** @use HasFactory<EmployeeDocumentFactory> */
    use HasFactory, LogsActivity;

    protected $fillable = ['employee_id', 'type', 'title', 'file_path', 'original_name', 'mime', 'size_bytes', 'expiry_date', 'uploaded_by'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'expiry_date' => 'date:Y-m-d',
        ];
    }

    /** Title and type only: never the stored path, the original file name or the contents. */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('employees')
            ->logOnly(['type', 'title', 'expiry_date'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->setDescriptionForEvent(fn (string $event) => "Employee document {$event}");
    }

    public function auditLabel(): string
    {
        return $this->employee->full_name.' (document: '.$this->title.')';
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** @return BelongsTo<User, $this> */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
