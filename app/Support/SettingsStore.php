<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;

/**
 * Request-scoped copy of the settings table. Bound with app()->scoped(), so the container
 * drops it between queued jobs and Octane requests and a new test starts empty.
 * Loads the whole table in one query on first read and never throws when the table is missing.
 */
class SettingsStore
{
    /** @var array<string, array<string, mixed>>|null group => key => decoded value */
    private ?array $rows = null;

    /** @return array<string, array<string, mixed>> */
    public function all(): array
    {
        if ($this->rows !== null) {
            return $this->rows;
        }

        try {
            $rows = [];

            foreach (Setting::query()->get(['group', 'key', 'value']) as $setting) {
                $decoded = $setting->value === null ? null : json_decode($setting->value, true);

                // Corrupt JSON: leave the key out so the default applies.
                if ($setting->value !== null && json_last_error() !== JSON_ERROR_NONE) {
                    continue;
                }

                $rows[$setting->group][$setting->key] = $decoded;
            }
        } catch (QueryException $e) {
            // Fresh install before migrations, or a deploy command running first: use defaults.
            // Anything else (database down, bad SQL) must still surface.
            if (Schema::hasTable('settings')) {
                throw $e;
            }

            // Cached too, so repeated reads in this request do not re-query.
            return $this->rows = [];
        }

        return $this->rows = $rows;
    }

    public function forget(): void
    {
        $this->rows = null;
    }
}
