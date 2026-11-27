<?php

namespace App\Support\System;

use Illuminate\Support\Facades\Artisan;
use Throwable;

/**
 * The backup taken before an update changes the database: the same `hrm:backup-database` the scheduler runs every night
 * (a verified gzip SQL dump on the private disk). Tests replace this class to make it fail.
 */
class DatabaseBackup
{
    /** @return array{ok: bool, output: string} */
    public function run(): array
    {
        try {
            $exit = Artisan::call('hrm:backup-database');

            return ['ok' => $exit === 0, 'output' => trim(Artisan::output())];
        } catch (Throwable $e) {
            report($e);

            return ['ok' => false, 'output' => 'The backup stopped with an error: '.$e->getMessage()];
        }
    }
}
