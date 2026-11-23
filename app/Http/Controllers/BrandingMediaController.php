<?php

namespace App\Http\Controllers;

use App\Support\Branding;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Serves the company logos from the private disk. Public on purpose (the login page shows the
 * logo) but limited to names Branding generated: 40 random characters plus png, jpg or webp.
 */
class BrandingMediaController extends Controller
{
    public function __invoke(string $filename): BinaryFileResponse
    {
        abort_unless(preg_match('#^'.Branding::FILENAME_PATTERN.'\z#', $filename) === 1, 404);

        $path = Branding::DIRECTORY.'/'.$filename;

        abort_unless(Branding::disk()->exists($path), 404);

        $extension = pathinfo($filename, PATHINFO_EXTENSION);

        return response()->file(Branding::disk()->path($path), [
            'Content-Type' => Branding::CONTENT_TYPES[$extension],
            // The name changes whenever the logo does, so the browser may keep it for a year.
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }
}
