<?php

namespace App\Console\Commands;

use App\Support\System\PendingMigrations;
use Illuminate\Console\Command;

/**
 * Prints the number of database changes waiting to be applied (digits only), for deploy/hostinger/cron.sh, which shows it
 * as pending_migrations=<n> in status.txt. An Admin applies them with "Update system" on the Updates screen.
 */
class CountPendingMigrations extends Command
{
    protected $signature = 'hrm:pending-migrations';

    protected $description = 'Print how many database changes are waiting for "Update system"';

    public function handle(PendingMigrations $migrations): int
    {
        $this->output->writeln((string) $migrations->count());

        return self::SUCCESS;
    }
}
