<?php

namespace App\Support;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Logos and the accent colour. Logos live on the private disk under branding/ (never the
 * public disk: the host replaces the document root on each deploy and blocks /storage) and
 * are served by the media.branding route. Only names this class generates are ever served.
 */
class Branding
{
    public const DIRECTORY = 'branding';

    public const DEFAULT_ACCENT = '#10b77f';

    /** Random 40-character name plus an extension we chose, never the uploaded name. */
    public const FILENAME_PATTERN = '[A-Za-z0-9]{40}\.(?:png|jpg|webp)';

    public const CONTENT_TYPES = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'webp' => 'image/webp'];

    private const MIME_TO_EXTENSION = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'];

    public static function disk(): Filesystem
    {
        return Storage::disk('local');
    }

    /** Stores an uploaded logo under a generated name and returns its path ("branding/<name>"). */
    public static function store(UploadedFile $file): string
    {
        $extension = self::MIME_TO_EXTENSION[(string) $file->getMimeType()] ?? null;

        abort_if($extension === null, 422, 'Unsupported logo type.');

        $name = Str::random(40).'.'.$extension;
        self::disk()->putFileAs(self::DIRECTORY, $file, $name);

        return self::DIRECTORY.'/'.$name;
    }

    /** Deletes a stored logo. Ignores anything that is not a path this class generated. */
    public static function delete(?string $path): void
    {
        if (self::filename($path) !== null) {
            self::disk()->delete($path);
        }
    }

    /** The filename of a stored logo path, or null when the value is empty or not one of ours. */
    public static function filename(mixed $path): ?string
    {
        if (! is_string($path) || ! preg_match('#^'.self::DIRECTORY.'/('.self::FILENAME_PATTERN.')\z#', $path, $m)) {
            return null;
        }

        return $m[1];
    }

    /** Relative URL of a stored logo, or null. Relative so it works on any host name. */
    public static function url(mixed $path): ?string
    {
        $filename = self::filename($path);

        return $filename === null ? null : route('media.branding', ['filename' => $filename], absolute: false);
    }

    /** The saved accent, or the default if the stored value is not a plain 6-digit hex colour. */
    public static function accent(): string
    {
        $accent = settings('branding.accent');

        return is_string($accent) && preg_match('/^#[0-9a-fA-F]{6}\z/', $accent) ? $accent : self::DEFAULT_ACCENT;
    }
}
