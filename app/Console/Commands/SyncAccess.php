<?php

namespace App\Console\Commands;

use App\Support\AccessSync;
use Illuminate\Console\Command;

class SyncAccess extends Command
{
    protected $signature = 'hrm:sync-access';

    protected $description = 'Create or repair roles and permissions, and create the first Admin from HRM_ADMIN_EMAIL/HRM_ADMIN_PASSWORD when none exists';

    public function handle(AccessSync $sync): int
    {
        $admin = $sync->run();

        // One line, lowercase letters, digits, spaces, "=" and "_" only: no email, no password.
        $this->output->writeln(sprintf(
            'access_sync roles=%d permissions=%d admin=%s',
            count(config('hrm.roles')),
            count(config('hrm.permissions')),
            $admin,
        ));

        return self::SUCCESS;
    }
}
