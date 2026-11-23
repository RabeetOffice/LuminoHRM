<?php

namespace App\Support;

/**
 * Resolves the optional "shared" directory that lives next to the application
 * directory (<base>/../shared).
 *
 * On the shared host a deploy may replace everything in the document root, so
 * the production .env and the storage directory (logs, cache, sessions, files)
 * live outside it, in "shared". When that directory does not exist (local
 * development, tests, CI) every method returns the framework default.
 *
 * Pure path logic on purpose: no framework or application dependency, so it can
 * run from bootstrap/app.php before anything else is booted.
 */
final class SharedDirectory
{
    private function __construct(private readonly string $path) {}

    public static function forBasePath(string $basePath): self
    {
        return new self(dirname($basePath).DIRECTORY_SEPARATOR.'shared');
    }

    public function path(): string
    {
        return $this->path;
    }

    public function exists(): bool
    {
        return is_dir($this->path);
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
