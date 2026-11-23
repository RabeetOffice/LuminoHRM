<?php

use App\Support\Settings;

if (! function_exists('settings')) {
    /**
     * Read one setting by "group.key", for example settings('attendance.grace_minutes').
     * Order: saved value, then the default in config('hrm.defaults'), then $default.
     */
    function settings(string $dotted, mixed $default = null): mixed
    {
        return Settings::get($dotted, $default);
    }
}
