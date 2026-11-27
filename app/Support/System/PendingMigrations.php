<?php

namespace App\Support\System;

use Illuminate\Database\Migrations\Migrator;

/**
 * Database changes (migration files) that are on the server but have not been run yet. Reads the same paths as
 * `artisan migrate`: database/migrations plus any path registered with the migrator.
 */
class PendingMigrations
{
    /** @return list<string> migration names, oldest first */
    public function names(): array
    {
        /** @var Migrator $migrator */
        $migrator = app('migrator');

        $paths = array_values(array_unique([...$migrator->paths(), database_path('migrations')]));
        $files = array_keys($migrator->getMigrationFiles($paths));

        if (! $migrator->repositoryExists()) {
            // Nothing has ever been run: every file is waiting.
            return $files;
        }

        $ran = $migrator->getRepository()->getRan();

        return array_values(array_diff($files, $ran));
    }

    public function count(): int
    {
        return count($this->names());
    }
}
