<?php

namespace App\Support;

use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\User;
use finfo;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use ZipArchive;

/**
 * Employee photos and documents on the private ("local") disk. The disk has no public URL (`serve` is false) and,
 * in production, lives in a persistent folder outside the web root. Files are reached only through the authorised
 * routes in EmployeeFileController.
 *
 *   employees/{id}/photo/{40 random characters}.{png|jpg|webp}
 *   employees/{id}/documents/{40 random characters}.{pdf|png|jpg|webp|doc|docx}
 *
 * No path ever comes from the request: stored names are generated here, the folder comes from the employee id, and
 * a path read back from the database is used only when it matches the pattern for THAT employee (isPhotoPath(),
 * isDocumentPath()). The type of an upload is read from its content (finfo plus signature checks), never from the
 * client's MIME type, and must agree with the file's extension.
 */
final class EmployeeFiles
{
    public const PHOTO_MAX_KB = 2048;

    public const PHOTO_MIN_SIDE = 64;

    public const PHOTO_MAX_SIDE = 2000;

    public const DOCUMENT_MAX_KB = 10240;

    public const MAX_DOCUMENTS = 50;

    /** Photo types by detected content type => the extension we store. No SVG, no GIF. */
    public const PHOTO_TYPES = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'];

    /** Content-Type sent for a stored photo, by stored extension. */
    public const PHOTO_CONTENT_TYPES = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'webp' => 'image/webp'];

    /** Document extensions a person may upload (jpeg is stored as jpg). */
    public const DOCUMENT_EXTENSIONS = ['pdf', 'png', 'jpg', 'jpeg', 'webp', 'doc', 'docx'];

    /** The MIME type recorded (and sent on download) for each stored document extension. */
    public const DOCUMENT_MIMES = [
        'pdf' => 'application/pdf',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'webp' => 'image/webp',
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    ];

    private const NAME = '[A-Za-z0-9]{40}';

    /** What finfo may call an old Word file (detectedMime() lowercases, so application/CDFV2, which some libmagic versions return for Word files, is listed in lowercase). */
    private const DOC_MIMES = ['application/msword', 'application/vnd.ms-office', 'application/x-ole-storage', 'application/cdfv2'];

    private const OLE_SIGNATURE = "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1";

    public static function disk(): Filesystem
    {
        return Storage::disk('local');
    }

    /** Whether $by may add, replace or remove this employee's photo and documents: the rule for editing the record. */
    public static function mayManage(User $by, Employee $employee): bool
    {
        return $by->can('employees.manage') && EmployeeRoles::mayTouchAccount($by, $employee->user()->first());
    }

    // ------------------------------------------------------------ reading an upload

    /** The content type finfo reads from the bytes, or null when the file cannot be read. */
    public static function detectedMime(UploadedFile $file): ?string
    {
        $path = $file->getRealPath();

        if ($path === false || ! is_file($path)) {
            return null;
        }

        // A file the system will not let us read (an antivirus may lock one) counts as unreadable, not as an error.
        $mime = @(new finfo(FILEINFO_MIME_TYPE))->file($path);

        return is_string($mime) ? strtolower($mime) : null;
    }

    /** The extension a photo is stored with (from its content), or null when it is not a PNG, JPEG or WebP image. */
    public static function photoExtension(UploadedFile $file): ?string
    {
        $extension = self::PHOTO_TYPES[self::detectedMime($file) ?? ''] ?? null;

        // The image itself must also be readable as that type (a header alone is not enough).
        return $extension !== null && self::imageSize($file) !== null ? $extension : null;
    }

    /**
     * [width, height] of a readable image, or null.
     *
     * @return array{0: int, 1: int}|null
     */
    public static function imageSize(UploadedFile $file): ?array
    {
        $path = $file->getRealPath();
        $size = $path === false ? false : @getimagesize($path);

        return is_array($size) && $size[0] > 0 && $size[1] > 0 ? [(int) $size[0], (int) $size[1]] : null;
    }

    /**
     * The extension a document is stored with, or null when the content is not the type its name claims (a ".pdf"
     * holding HTML, a ".docx" that is not a Word file) or the type is not allowed at all.
     */
    public static function documentExtension(UploadedFile $file): ?string
    {
        $claimed = strtolower($file->getClientOriginalExtension());
        $claimed = $claimed === 'jpeg' ? 'jpg' : $claimed;

        if (! in_array($claimed, self::DOCUMENT_EXTENSIONS, true)) {
            return null;
        }

        $mime = self::detectedMime($file);
        $head = self::head($file, 8);

        $matches = match ($claimed) {
            'pdf' => $mime === 'application/pdf' && str_starts_with($head, '%PDF-'),
            'png', 'jpg', 'webp' => (self::PHOTO_TYPES[$mime ?? ''] ?? null) === $claimed && self::imageSize($file) !== null,
            // The OLE signature alone also matches MSI, XLS and MSG files, so finfo must call it a Word/Office container too.
            'doc' => str_starts_with($head, self::OLE_SIGNATURE) && in_array($mime, self::DOC_MIMES, true),
            'docx' => str_starts_with($head, "PK\x03\x04") && self::isWordPackage($file, $mime),
            default => false,
        };

        return $matches ? $claimed : null;
    }

    /** A zip that holds the parts of a Word 2007+ document. */
    private static function isWordPackage(UploadedFile $file, ?string $mime): bool
    {
        if (! class_exists(ZipArchive::class)) {
            // Without the zip extension, trust finfo's reading of the package.
            return $mime === self::DOCUMENT_MIMES['docx'];
        }

        $zip = new ZipArchive;

        if ($zip->open((string) $file->getRealPath(), ZipArchive::RDONLY) !== true) {
            return false;
        }

        $ok = $zip->locateName('[Content_Types].xml') !== false && $zip->locateName('word/document.xml') !== false;
        $zip->close();

        return $ok;
    }

    /** @param  positive-int  $bytes */
    private static function head(UploadedFile $file, int $bytes): string
    {
        $path = $file->getRealPath();

        return $path === false ? '' : (string) @file_get_contents($path, false, null, 0, $bytes);
    }

    /** An uploaded file name made safe to keep and show: no folders, control characters or quotes; at most 200 characters with the extension kept. */
    public static function cleanName(string $name): string
    {
        $name = mb_convert_encoding($name, 'UTF-8', 'UTF-8');
        // Also strips zero-width and bidi control characters (a right-to-left override can disguise an extension).
        $name = (string) preg_replace('#[/\\\\"\'\x00-\x1F\x7F\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{FEFF}]+#u', '', $name);
        $name = trim(ltrim(trim($name), '.'));

        if (mb_strlen($name) <= 200) {
            return $name;
        }

        $extension = pathinfo($name, PATHINFO_EXTENSION);
        $suffix = $extension !== '' && mb_strlen($extension) <= 10 ? '.'.$extension : '';

        return rtrim(mb_substr($name, 0, 200 - mb_strlen($suffix))).$suffix;
    }

    // ------------------------------------------------------------ storing and removing

    public static function directory(int $employeeId): string
    {
        return "employees/{$employeeId}";
    }

    /**
     * The photo re-encoded with GD so EXIF and other metadata (GPS position, device) are dropped, or null when it
     * cannot be decoded. A JPEG's EXIF orientation is applied first so a phone photo stays upright: read with
     * exif_read_data when the exif extension is loaded, otherwise with jpegOrientation(). PNG and WebP keep their
     * transparency. Where this PHP build cannot write WebP, the original bytes are kept.
     */
    public static function cleanPhotoBytes(UploadedFile $file, string $extension): ?string
    {
        $path = $file->getRealPath();
        $original = $path === false ? false : @file_get_contents($path);

        if (! is_string($original) || $original === '') {
            return null;
        }

        if ($extension === 'webp' && ! function_exists('imagewebp')) {
            return $original;
        }

        $image = @imagecreatefromstring($original);

        if ($image === false) {
            return null;
        }

        if ($extension === 'jpg') {
            $image = self::applyOrientation($image, self::orientationOf((string) $path, $original));
        }

        if ($extension !== 'jpg') {
            imagealphablending($image, false);
            imagesavealpha($image, true);
        }

        ob_start();
        $ok = match ($extension) {
            'jpg' => imagejpeg($image, null, 85),
            'png' => imagepng($image),
            default => imagewebp($image, null, 85),
        };
        $bytes = (string) ob_get_clean();

        return $ok && $bytes !== '' ? $bytes : null;
    }

    /** The EXIF orientation of a JPEG (1-8) or null: from the exif extension when it is loaded, else read from the bytes. */
    private static function orientationOf(string $path, string $bytes): ?int
    {
        if (function_exists('exif_read_data')) {
            $exif = @exif_read_data($path);
            $value = is_array($exif) ? ($exif['Orientation'] ?? null) : null;

            return is_int($value) && $value >= 1 && $value <= 8 ? $value : null;
        }

        return self::jpegOrientation($bytes);
    }

    /** At most this many JPEG segments are walked looking for the EXIF one. */
    private const MAX_JPEG_SEGMENTS = 64;

    /** At most this many IFD0 entries are read looking for the Orientation tag. */
    private const MAX_IFD_ENTRIES = 256;

    /**
     * The EXIF Orientation (1-8) read straight from JPEG bytes, or null when there is none or anything about it cannot
     * be trusted. Used when the exif extension is missing. Finds the APP1 segment that starts with "Exif\0\0", reads
     * the TIFF byte order (II or MM), and looks through IFD0 for tag 0x0112. Every length and offset comes from the
     * file, so each is checked against the bytes actually there, and the segments and entries walked are capped.
     */
    public static function jpegOrientation(string $bytes): ?int
    {
        $length = strlen($bytes);

        if ($length < 4 || ! str_starts_with($bytes, "\xFF\xD8")) {
            return null;
        }

        $pos = 2;

        for ($segment = 0; $segment < self::MAX_JPEG_SEGMENTS && $pos + 4 <= $length; $segment++) {
            if ($bytes[$pos] !== "\xFF") {
                return null;
            }

            // Any number of 0xFF fill bytes may come before a marker.
            while ($pos + 1 < $length && $bytes[$pos + 1] === "\xFF") {
                $pos++;
            }

            if ($pos + 4 > $length) {
                return null;
            }

            $marker = ord($bytes[$pos + 1]);

            // Start of scan or end of image: the metadata segments are behind us.
            if ($marker === 0xDA || $marker === 0xD9) {
                return null;
            }

            $size = self::unpackInt('n', $bytes, $pos + 2);

            if ($size < 2 || $pos + 2 + $size > $length) {
                return null;
            }

            if ($marker === 0xE1 && $size >= 8 && substr($bytes, $pos + 4, 6) === "Exif\0\0") {
                return self::tiffOrientation(substr($bytes, $pos + 10, $size - 8));
            }

            $pos += 2 + $size;
        }

        return null;
    }

    /** The first value unpack() reads at the offset. Callers bounds-check first, so the 0 for a failed read is never used. */
    private static function unpackInt(string $format, string $data, int $offset): int
    {
        $values = unpack($format, $data, $offset);

        return is_array($values) ? (int) $values[1] : 0;
    }

    /** The Orientation in IFD0 of a TIFF block (the part of an EXIF segment after "Exif\0\0"), or null. */
    private static function tiffOrientation(string $tiff): ?int
    {
        $length = strlen($tiff);

        if ($length < 8) {
            return null;
        }

        [$u16, $u32] = match (substr($tiff, 0, 2)) {
            'II' => ['v', 'V'],
            'MM' => ['n', 'N'],
            default => [null, null],
        };

        if ($u16 === null || self::unpackInt($u16, $tiff, 2) !== 42) {
            return null;
        }

        $ifd = self::unpackInt($u32, $tiff, 4);

        if ($ifd < 8 || $ifd + 2 > $length) {
            return null;
        }

        $entries = min(self::unpackInt($u16, $tiff, $ifd), self::MAX_IFD_ENTRIES);

        for ($i = 0; $i < $entries; $i++) {
            $entry = $ifd + 2 + 12 * $i;

            if ($entry + 12 > $length) {
                return null;
            }

            if (self::unpackInt($u16, $tiff, $entry) !== 0x0112) {
                continue;
            }

            // Type SHORT (3) and count 1: the value sits in the first two bytes of the value field.
            if (self::unpackInt($u16, $tiff, $entry + 2) !== 3 || self::unpackInt($u32, $tiff, $entry + 4) !== 1) {
                return null;
            }

            $value = self::unpackInt($u16, $tiff, $entry + 8);

            return $value >= 1 && $value <= 8 ? $value : null;
        }

        return null;
    }

    /**
     * $image turned and flipped so it displays upright for an EXIF orientation (1 or null: unchanged). GD turns
     * anticlockwise for a positive angle.
     */
    public static function applyOrientation(\GdImage $image, ?int $orientation): \GdImage
    {
        if (in_array($orientation, [2, 4, 5, 7], true)) {
            imageflip($image, $orientation === 4 ? IMG_FLIP_VERTICAL : IMG_FLIP_HORIZONTAL);
        }

        $angle = match ($orientation) {
            3 => 180,
            6, 7 => -90,
            5, 8 => 90,
            default => null,
        };

        if ($angle === null) {
            return $image;
        }

        $turned = imagerotate($image, $angle, 0);

        return $turned === false ? $image : $turned;
    }

    /** Stores the photo (re-encoded, metadata dropped) under a generated name and returns its path. The caller checked photoExtension(). */
    public static function storePhoto(Employee $employee, UploadedFile $file, string $extension): string
    {
        $bytes = self::cleanPhotoBytes($file, $extension);

        if ($bytes === null) {
            throw new PhotoNotProcessable('The photo could not be read.');
        }

        $folder = self::directory($employee->id).'/photo';
        $name = Str::random(40).'.'.$extension;

        if (! self::disk()->put($folder.'/'.$name, $bytes)) {
            throw new \RuntimeException('The file could not be stored.');
        }

        return $folder.'/'.$name;
    }

    /** Stores a document under a generated name and returns its path. The caller checked documentExtension(). */
    public static function storeDocument(Employee $employee, UploadedFile $file, string $extension): string
    {
        return self::store(self::directory($employee->id).'/documents', $file, $extension);
    }

    private static function store(string $folder, UploadedFile $file, string $extension): string
    {
        $name = Str::random(40).'.'.$extension;
        $stored = self::disk()->putFileAs($folder, $file, $name);

        if ($stored === false) {
            throw new \RuntimeException('The file could not be stored.');
        }

        return $folder.'/'.$name;
    }

    /** Whether $path is a photo name this class generated for this employee. */
    public static function isPhotoPath(int $employeeId, mixed $path): bool
    {
        return is_string($path)
            && preg_match('#^employees/'.$employeeId.'/photo/'.self::NAME.'\.(?:png|jpg|webp)\z#', $path) === 1;
    }

    /** Whether $path is a document name this class generated for this employee. */
    public static function isDocumentPath(int $employeeId, mixed $path): bool
    {
        return is_string($path)
            && preg_match('#^employees/'.$employeeId.'/documents/'.self::NAME.'\.(?:pdf|png|jpg|webp|doc|docx)\z#', $path) === 1;
    }

    /** Deletes a stored photo of this employee; anything else (another folder, a missing file) is left alone. */
    public static function deletePhoto(int $employeeId, mixed $path): void
    {
        if (self::isPhotoPath($employeeId, $path)) {
            self::disk()->delete($path);
        }
    }

    /** Deletes a stored document of this employee; anything else is left alone. A file already gone is fine. */
    public static function deleteDocument(int $employeeId, mixed $path): void
    {
        if (self::isDocumentPath($employeeId, $path)) {
            self::disk()->delete($path);
        }
    }

    /** Removes everything stored for this employee (used when the record is deleted). */
    public static function deleteAll(int $employeeId): void
    {
        self::disk()->deleteDirectory(self::directory($employeeId));
    }

    // ------------------------------------------------------------ what pages receive

    /** The photo route with a version that changes when the record does, or null when there is no photo. Relative. */
    public static function photoUrl(Employee $employee): ?string
    {
        if (! self::isPhotoPath($employee->id, $employee->photo_path)) {
            return null;
        }

        return route('employees.photo.show', [
            'employee' => $employee->id,
            'v' => $employee->updated_at?->getTimestamp() ?? 0,
        ], absolute: false);
    }

    /**
     * The documents as pages get them: never the stored path. Call only for a viewer who has the `documents` group.
     *
     * @return list<array{id: int, type: string, title: string, original_name: string, size_bytes: int, uploaded_at: string|null, expiry_date: string|null, download_url: string}>
     */
    public static function documentItems(Employee $employee): array
    {
        return array_values($employee->documents()
            ->orderBy('created_at')
            ->orderBy('id')
            ->get(['id', 'employee_id', 'type', 'title', 'original_name', 'size_bytes', 'expiry_date', 'created_at'])
            ->map(fn (EmployeeDocument $document) => [
                'id' => $document->id,
                'type' => $document->type,
                'title' => $document->title,
                'original_name' => $document->original_name,
                'size_bytes' => $document->size_bytes,
                'uploaded_at' => $document->created_at?->format('Y-m-d'),
                'expiry_date' => $document->expiry_date?->format('Y-m-d'),
                'download_url' => route('employees.documents.download', [$employee->id, $document->id], absolute: false),
            ])
            ->all());
    }

    /** The name a download is saved under: the cleaned original name with the stored extension, or document-{id}.{ext}. */
    public static function downloadName(EmployeeDocument $document, string $extension): string
    {
        $name = self::cleanName((string) $document->original_name);

        if ($name === '') {
            return "document-{$document->id}.{$extension}";
        }

        $own = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $own = $own === 'jpeg' ? 'jpg' : $own;

        return $own === $extension ? $name : "{$name}.{$extension}";
    }
}
