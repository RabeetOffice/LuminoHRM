<?php

namespace App\Support;

/**
 * Resolves the optional "shared" directory that lives next to the application
 * directory (<base>/../shared).
 *
 * On the shared host a deploy may replace everything in the document root, so
 * the production .env and the storage directory (logs, cache, sessions, files)
 * live outside it, in "shared". The directory counts only when it carries a marker
 * (see exists()), so an unrelated sibling folder called "shared" on a developer PC is
 * ignored. When it does not count (local development, tests, CI) every method returns
 * the framework default.
 *
 * Pure path logic on purpose: no framework or application dependency, so it can
 * run from bootstrap/app.php before anything else is booted.
 */
final class SharedDirectory
{
    public const MARKER = '.lumino-hrm';

    public const LEGACY_MARKER = 'release.done';

    private function __construct(private readonly string $path) {}

    public static function forBasePath(string $basePath): self
    {
        return new self(dirname($basePath).DIRECTORY_SEPARATOR.'shared');
    }

    public function path(): string
    {
        return $this->path;
    }

    /**
     * The directory is the app's shared directory only when it exists AND holds the marker
     * file .lumino-hrm (cron.sh creates it on every run) or release.done (an already-deployed
     * server, before the marker was introduced).
     */
    public function exists(): bool
    {
        return is_dir($this->path)
            && (is_file($this->path.DIRECTORY_SEPARATOR.self::MARKER)
                || is_file($this->path.DIRECTORY_SEPARATOR.self::LEGACY_MARKER));
    }

    /** Directory that holds the .env file. */
    public function environmentPath(string $default): string
    {
        return $this->exists() ? $this->path : $default;
    }

    /** Directory used as the storage path. */
    public function storagePath(string $basePath): string
    {
        return ($this->exists() ? $this->path : $basePath).DIRECTORY_SEPARATOR.'storage';
    }
}
