<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One stored setting. Read and write through App\Support\Settings, never directly:
 * the `value` column is JSON, and the email password is stored encrypted.
 * Never add this model to an audit trail with its values (the email group holds a secret).
 *
 * @property int $id
 * @property string $group
 * @property string $key
 * @property string|null $value
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Setting extends Model
{
    protected $fillable = ['group', 'key', 'value'];
}
