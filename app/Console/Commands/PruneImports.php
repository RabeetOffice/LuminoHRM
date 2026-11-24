<?php

namespace App\Console\Commands;

use App\Support\EmployeeImportStore;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Hourly (routes/console.php). Deletes uploaded employee import files older than 60 minutes, and the Excel package's
 * own temporary copies (config excel.temporary_files.local_path) older than 60 minutes: a download the browser
 * aborted can leave a finished export, with personal data, behind there.
 */
class PruneImports extends Command
{
    protected $signature = 'hrm:prune-imports';

    protected $description = 'Delete uploaded employee import files and Excel temporary files older than 60 minutes';

    public function handle(): int
    {
        $imports = EmployeeImportStore::prune();
        $temporary = 0;
        $directory = (string) config('excel.temporary_files.local_path');
        $cutoff = now()->subMinutes(EmployeeImportStore::TTL_MINUTES)->getTimestamp();

        if ($directory !== '' && File::isDirectory($directory)) {
            foreach (File::files($directory) as $file) {
                if ($file->getMTime() < $cutoff && File::delete($file->getPathname())) {
                    $temporary++;
                }
            }
        }

        $this->output->writeln("prune_imports deleted={$imports} temporary={$temporary}");

        return self::SUCCESS;
    }
}
