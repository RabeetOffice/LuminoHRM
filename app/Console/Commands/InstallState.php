<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Prints "fresh" or "installed" (one word, nothing else) for deploy/hostinger/cron.sh, which runs `migrate --force` and
 * `hrm:sync-access` by itself only on a fresh install. On an installed system database changes wait for an Admin on the
 * Updates screen.
 *
 * Fresh = no migration has ever run, or no account exists yet (the first deploy, also one whose first migrate stopped
 * half-way before the first Admin was made: nobody could sign in to finish it from the Updates screen). Exit code 1 when
 * the database cannot be reached, so the deploy step retries instead of guessing.
 */
class InstallState extends Command
{
    protected $signature = 'hrm:install-state';

    protected $description = 'Print "fresh" (no migrations or no accounts yet) or "installed", for the deploy script';

    public const FRESH = 'fresh';

    public const INSTALLED = 'installed';

    public function handle(): int
    {
        $this->output->writeln($this->state());

        return self::SUCCESS;
    }

    public function state(): string
    {
        if (! Schema::hasTable('migrations') || ! DB::table('migrations')->exists()) {
            return self::FRESH;
        }

        if (! Schema::hasTable('users') || ! User::query()->exists()) {
            return self::FRESH;
        }

        return self::INSTALLED;
    }
}
