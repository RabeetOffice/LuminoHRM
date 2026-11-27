<?php

namespace App\Support\System;

use Carbon\CarbonImmutable;
use Throwable;

/**
 * The list of versions in resources/changelog.php, newest first. Its top entry is the version of the code that is on the
 * server; the Updates screen compares it with the version the last successful "Update system" recorded.
 *
 * @phpstan-type Change array{type: string, text: string}
 * @phpstan-type Release array{version: string, date: string, title: string, changes: list<Change>}
 */
final class Changelog
{
    /** Change types, in the order the screen explains them, with their badge label. */
    public const TYPES = [
        'new' => 'New',
        'improved' => 'Improved',
        'fixed' => 'Fixed',
        'removed' => 'Removed',
    ];

    /** @var list<Release>|null */
    private static ?array $releases = null;

    private static ?string $file = null;

    /** @return list<Release> */
    public static function releases(): array
    {
        if (self::$releases !== null) {
            return self::$releases;
        }

        $file = self::$file ?? resource_path('changelog.php');
        $raw = is_file($file) ? require $file : [];
        $releases = [];

        foreach (is_array($raw) ? $raw : [] as $entry) {
            if (! is_array($entry) || ! is_string($entry['version'] ?? null) || $entry['version'] === '') {
                continue;
            }

            $changes = [];

            foreach ((array) ($entry['changes'] ?? []) as $change) {
                if (is_array($change) && isset($change[0], $change[1])) {
                    $type = (string) $change[0];
                    $changes[] = ['type' => array_key_exists($type, self::TYPES) ? $type : 'improved', 'text' => (string) $change[1]];
                }
            }

            $releases[] = [
                'version' => $entry['version'],
                'date' => self::date($entry['date'] ?? null),
                'title' => (string) ($entry['title'] ?? 'Update'),
                'changes' => $changes,
            ];
        }

        usort($releases, fn (array $a, array $b): int => version_compare($b['version'], $a['version']));

        return self::$releases = $releases;
    }

    /** The version of the code on this server. */
    public static function latestVersion(): string
    {
        return self::releases()[0]['version'] ?? '0.0.0';
    }

    /**
     * Versions newer than the installed one: what an update brings. Before the first update (nothing installed yet)
     * only the newest version counts as new.
     *
     * @return list<Release>
     */
    public static function since(?string $installed): array
    {
        if ($installed === null) {
            return array_slice(self::releases(), 0, 1);
        }

        return array_values(array_filter(
            self::releases(),
            fn (array $release): bool => version_compare($release['version'], $installed, '>'),
        ));
    }

    /** Tests read another file (null: back to resources/changelog.php). */
    public static function useFile(?string $file): void
    {
        self::$file = $file;
        self::$releases = null;
    }

    public static function forget(): void
    {
        self::$releases = null;
    }

    /** A plain "Y-m-d" string: props never carry date objects. */
    private static function date(mixed $value): string
    {
        try {
            return CarbonImmutable::parse(is_string($value) ? $value : 'today')->format('Y-m-d');
        } catch (Throwable) {
            return '';
        }
    }
}
