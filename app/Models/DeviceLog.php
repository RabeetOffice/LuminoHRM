<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DeviceLog extends Model
{
    protected $fillable = [
        'serial_no', 'method', 'path', 'query', 'body', 'ip', 'scheme', 'accepted', 'summary',
    ];

    protected function casts(): array
    {
        return ['accepted' => 'boolean'];
    }
}
