<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Uploaded import files between the preview and the run, on the private "local" disk:
 *
 *   imports/{token}.xlsx   the file exactly as uploaded (never the client's file name)
 *   imports/{token}.json   {"user_id": 7, "kind": "employees", "sha256": "...", "created_at": 1764000000}
 *
 * The token is 48 random letters and digits, bound to the user who uploaded it and to the kind of import (KIND_*),
 * valid for 60 minutes, and single-use (the run deletes both files). A user has at most one pending import of each
 * kind: a new upload deletes their previous one of the same kind only (an employee preview survives a leave-opening
 * upload and the other way round). A sidecar without a kind (written before kinds existed) is an employee import. The
 * scheduler deletes anything older than 60 minutes (hrm:prune-imports).
 */
final class EmployeeImportStore
{
    public const DIRECTORY = 'imports';

    public const TTL_MINUTES = 60;

    public const KIND_EMPLOYEES = 'employees';

    public const KIND_LEAVE_OPENING = 'leave-opening';

    private const TOKEN_PATTERN = '/^[A-Za-z0-9]{48}$/';

    public static function disk(): Filesystem
    {
        return Storage::disk('local');
    }

    /** Stores the upload for $user (replacing their previous pending one of this kind) and returns its token. */
    public static function put(UploadedFile $file, User $user, string $kind = self::KIND_EMPLOYEES): string
    {
        self::forgetUser($user, $kind);

        $token = Str::random(48);
        $disk = self::disk();
        $disk->putFileAs(self::DIRECTORY, $file, "{$token}.xlsx");
        $disk->put(self::DIRECTORY."/{$token}.json", (string) json_encode([
            'user_id' => $user->getKey(),
            'kind' => $kind,
            'sha256' => hash_file('sha256', $disk->path(self::DIRECTORY."/{$token}.xlsx")),
            'created_at' => now()->getTimestamp(),
        ]));

        return $token;
    }

    /**
     * The absolute path of the pending file, or null when the token is malformed, unknown, another user's, of another
     * kind, older than 60 minutes, or the file no longer matches the hash taken at upload. An expired or altered upload
     * is deleted (one of another kind is left alone).
     */
    public static function path(mixed $token, User $user, string $kind = self::KIND_EMPLOYEES): ?string
    {
        if (! is_string($token) || preg_match(self::TOKEN_PATTERN, $token) !== 1) {
            return null;
        }

        $meta = self::meta($token);
        $disk = self::disk();
        $file = self::DIRECTORY."/{$token}.xlsx";

        if ($meta === null || ! $disk->exists($file) || (int) $meta['user_id'] !== (int) $user->getKey() || $meta['kind'] !== $kind) {
            return null;
        }

        if (self::isExpired($meta['created_at']) || ! hash_equals($meta['sha256'], (string) hash_file('sha256', $disk->path($file)))) {
            self::forget($token);

            return null;
        }

        return $disk->path($file);
    }

    public static function forget(string $token): void
    {
        if (preg_match(self::TOKEN_PATTERN, $token) === 1) {
            self::disk()->delete([self::DIRECTORY."/{$token}.xlsx", self::DIRECTORY."/{$token}.json"]);
        }
    }

    /** Deletes every file in the imports folder older than 60 minutes (by its sidecar, else by its file time). */
    public static function prune(): int
    {
        $disk = self::disk();
        $deleted = 0;

        foreach ($disk->files(self::DIRECTORY) as $path) {
            $name = pathinfo($path, PATHINFO_FILENAME);
            $meta = self::meta($name);
            $time = $meta['created_at'] ?? $disk->lastModified($path);

            if (self::isExpired($time)) {
                $disk->delete($path);
                $deleted++;
            }
        }

        return $deleted;
    }

    private static function forgetUser(User $user, string $kind): void
    {
        foreach (self::disk()->files(self::DIRECTORY) as $path) {
            if (str_ends_with($path, '.json')) {
                $token = pathinfo($path, PATHINFO_FILENAME);
                $meta = self::meta($token);

                if ($meta !== null && (int) $meta['user_id'] === (int) $user->getKey() && $meta['kind'] === $kind) {
                    self::forget($token);
                }
            }
        }
    }

    /** @return array{user_id: int, kind: string, sha256: string, created_at: int}|null */
    private static function meta(string $token): ?array
    {
        $path = self::DIRECTORY."/{$token}.json";

        if (preg_match(self::TOKEN_PATTERN, $token) !== 1 || ! self::disk()->exists($path)) {
            return null;
        }

        $meta = json_decode((string) self::disk()->get($path), true);

        if (! is_array($meta) || ! isset($meta['user_id'], $meta['sha256'], $meta['created_at']) || ! is_string($meta['sha256'])) {
            return null;
        }

        return [
            'user_id' => (int) $meta['user_id'],
            'kind' => is_string($meta['kind'] ?? null) ? $meta['kind'] : self::KIND_EMPLOYEES,
            'sha256' => $meta['sha256'],
            'created_at' => (int) $meta['created_at'],
        ];
    }

    private static function isExpired(int $timestamp): bool
    {
        return now()->getTimestamp() - $timestamp > self::TTL_MINUTES * 60;
    }
}
