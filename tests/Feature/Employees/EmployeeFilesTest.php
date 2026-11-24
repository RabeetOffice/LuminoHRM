<?php

use App\Models\Department;
use App\Models\Designation;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\User;
use App\Support\EmployeeFiles;
use App\Support\EmployeeRoles;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    Storage::fake('local');
});

// ---------------------------------------------------------------- fixtures

const EF_PDF = "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n";
const EF_HTML = "<!DOCTYPE html>\n<html><body><script>alert('x')</script></body></html>\n";

function efDept(): array
{
    $dept = Department::first() ?? Department::factory()->create(['name' => 'Engineering']);
    $desig = Designation::where('department_id', $dept->id)->first() ?? Designation::factory()->create(['department_id' => $dept->id, 'name' => 'Engineer']);

    return ['department_id' => $dept->id, 'designation_id' => $desig->id];
}

function efEmployee(array $attrs = []): Employee
{
    return Employee::factory()->create(array_merge(efDept(), $attrs));
}

/** The bytes of a real 80x80 PNG (the fake keeps its temp file only while the object lives). */
function efPngBytes(): string
{
    $image = UploadedFile::fake()->image('x.png', 80, 80);

    return (string) file_get_contents($image->getRealPath());
}

function efPdf(string $name = 'contract.pdf'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, EF_PDF);
}

/** A real (tiny) Word 2007+ file: a zip with the parts Word needs. */
function efDocx(string $name = 'offer.docx', bool $word = true): UploadedFile
{
    $tmp = tempnam(sys_get_temp_dir(), 'efdocx');
    $zip = new ZipArchive;
    $zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>');
    if ($word) {
        $zip->addFromString('word/document.xml', '<?xml version="1.0"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"/>');
    } else {
        $zip->addFromString('payload.txt', 'not a word file');
    }
    $zip->close();
    $content = (string) file_get_contents($tmp);
    @unlink($tmp);

    return UploadedFile::fake()->createWithContent($name, $content);
}

/** An old-style Word file: the OLE container signature and some padding. */
function efDoc(string $name = 'letter.doc'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1".str_repeat("\0", 1024));
}

/** Stores a photo for $employee straight on the disk, as the upload would. */
function efGivePhoto(Employee $employee): string
{
    $path = "employees/{$employee->id}/photo/".Str::random(40).'.png';
    Storage::disk('local')->put($path, efPngBytes());
    $employee->forceFill(['photo_path' => $path])->saveQuietly();

    return $path;
}

/** Stores a document for $employee straight on the disk, as the upload would. */
function efGiveDocument(Employee $employee, array $attrs = []): EmployeeDocument
{
    $path = "employees/{$employee->id}/documents/".Str::random(40).'.pdf';
    Storage::disk('local')->put($path, EF_PDF);

    return EmployeeDocument::factory()->create(array_merge([
        'employee_id' => $employee->id,
        'type' => 'contract',
        'title' => 'Signed contract',
        'file_path' => $path,
        'original_name' => 'contract.pdf',
        'mime' => 'application/pdf',
        'size_bytes' => strlen(EF_PDF),
    ], $attrs));
}

function efAdmin(): User
{
    return userWithRole('admin');
}

/** Files stored under a folder of the fake disk. */
function efFiles(string $dir): array
{
    return Storage::disk('local')->allFiles($dir);
}

// ---------------------------------------------------------------- who may do what (every route, every role)

/**
 * Builds the people and returns [actor, target] for a case. Every target has a photo and one document.
 */
function efCase(string $case): array
{
    $make = function (string $role, array $attrs = []): array {
        $user = userWithRole($role);

        return [$user, employeeFor($user, array_merge(efDept(), $attrs))];
    };

    [$actor, $target] = match ($case) {
        'admin -> anyone' => [efAdmin(), efEmployee()],
        'hr -> anyone' => [userWithRole('hr'), efEmployee()],
        'manager -> report' => (function () use ($make) {
            [$manager, $own] = $make('manager');

            return [$manager, efEmployee(['manager_id' => $own->id])];
        })(),
        'manager -> stranger' => (function () use ($make) {
            [$manager] = $make('manager');

            return [$manager, efEmployee()];
        })(),
        'employee -> self' => (function () use ($make) {
            [$user, $own] = $make('employee');

            return [$user, $own];
        })(),
        'employee -> other' => (function () use ($make) {
            [$user] = $make('employee');

            return [$user, efEmployee()];
        })(),
        'hr -> admin person' => (function () use ($make) {
            [, $adminPerson] = $make('admin');

            return [userWithRole('hr'), $adminPerson];
        })(),
        'hr -> hr person' => (function () use ($make) {
            [, $hrPerson] = $make('hr');

            return [userWithRole('hr'), $hrPerson];
        })(),
        'admin -> admin person' => (function () use ($make) {
            [, $adminPerson] = $make('admin');

            return [efAdmin(), $adminPerson];
        })(),
    };

    efGivePhoto($target);
    efGiveDocument($target);

    return [$actor->refresh(), $target->refresh()];
}

it('answers each route by role', function (string $case, int $photo, int $download, bool $write) {
    [$actor, $target] = efCase($case);
    $doc = $target->documents()->first();
    $id = $target->id;
    $writeCode = $write ? 302 : 403;

    $this->actingAs($actor)->get("/employees/{$id}/photo")->assertStatus($photo);
    $this->actingAs($actor)->get("/employees/{$id}/documents/{$doc->id}")->assertStatus($download);
    $this->actingAs($actor)->post("/employees/{$id}/photo", ['photo' => UploadedFile::fake()->image('new.png', 100, 100)])->assertStatus($writeCode);
    $this->actingAs($actor)->post("/employees/{$id}/documents", ['type' => 'contract', 'title' => 'Another', 'file' => efPdf()])->assertStatus($writeCode);
    $this->actingAs($actor)->delete("/employees/{$id}/documents/{$doc->id}")->assertStatus($writeCode);
    $this->actingAs($actor)->delete("/employees/{$id}/photo")->assertStatus($writeCode);

    if (! $write) {
        // Refused writes change nothing: same photo, same single document, same files.
        expect($target->fresh()->photo_path)->toBe($target->photo_path)
            ->and($target->documents()->count())->toBe(1)
            ->and(efFiles("employees/{$id}"))->toHaveCount(2);
    } else {
        expect($target->fresh()->photo_path)->toBeNull()
            ->and($target->documents()->pluck('title')->all())->toBe(['Another']);
    }
})->with([
    ['admin -> anyone', 200, 200, true],
    ['hr -> anyone', 200, 200, true],
    ['manager -> report', 200, 403, false],
    ['manager -> stranger', 403, 403, false],
    ['employee -> self', 200, 200, false],
    ['employee -> other', 403, 403, false],
    ['hr -> admin person', 200, 200, false],
    ['hr -> hr person', 200, 200, false],
    ['admin -> admin person', 200, 200, true],
]);

it('sends guests to the login page and never serves them a file', function () {
    $e = efEmployee();
    efGivePhoto($e);
    $doc = efGiveDocument($e);

    $this->get("/employees/{$e->id}/photo")->assertRedirect('/login');
    $this->get("/employees/{$e->id}/documents/{$doc->id}")->assertRedirect('/login');
    $this->post("/employees/{$e->id}/documents", ['type' => 'contract', 'title' => 'x', 'file' => efPdf()])->assertRedirect('/login');
});

it('names the routes and constrains the ids to digits', function () {
    expect(route('employees.photo.show', 5, false))->toBe('/employees/5/photo')
        ->and(route('employees.photo.update', 5, false))->toBe('/employees/5/photo')
        ->and(route('employees.photo.destroy', 5, false))->toBe('/employees/5/photo')
        ->and(route('employees.documents.store', 5, false))->toBe('/employees/5/documents')
        ->and(route('employees.documents.download', [5, 7], false))->toBe('/employees/5/documents/7')
        ->and(route('employees.documents.destroy', [5, 7], false))->toBe('/employees/5/documents/7');

    $admin = efAdmin();
    $this->actingAs($admin)->get('/employees/abc/photo')->assertNotFound();
    $this->actingAs($admin)->get('/employees/1/documents/abc')->assertNotFound();
    $this->actingAs($admin)->get('/employees/99999/photo')->assertNotFound();
});

// ---------------------------------------------------------------- the photo

it('stores a photo on the private disk under a random name in the employee folder', function (string $name, string $ext) {
    $e = efEmployee();

    $this->actingAs(efAdmin())->post("/employees/{$e->id}/photo", ['photo' => UploadedFile::fake()->image($name, 120, 90)])
        ->assertRedirect()->assertSessionHasNoErrors()->assertSessionHas('success', 'Photo updated.');

    $path = $e->fresh()->photo_path;
    expect($path)->toMatch('#^employees/'.$e->id.'/photo/[A-Za-z0-9]{40}\.'.$ext.'$#')
        ->and(Storage::disk('local')->exists($path))->toBeTrue()
        ->and($path)->not->toContain(pathinfo($name, PATHINFO_FILENAME));
})->with([['face.png', 'png'], ['face.jpg', 'jpg'], ['face.jpeg', 'jpg'], ['face.webp', 'webp']]);

it('refuses a photo of the wrong type, size or dimensions', function (callable $file, string $message) {
    $e = efEmployee();

    $this->actingAs(efAdmin())->post("/employees/{$e->id}/photo", ['photo' => $file()])
        ->assertSessionHasErrors(['photo' => $message]);

    expect($e->fresh()->photo_path)->toBeNull()->and(efFiles('employees'))->toBe([]);
})->with([
    'gif' => [fn () => UploadedFile::fake()->image('a.gif', 100, 100), 'The photo must be a PNG, JPG or WebP image.'],
    'svg with a script' => [fn () => UploadedFile::fake()->createWithContent('a.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'), 'The photo must be a PNG, JPG or WebP image.'],
    'svg named png' => [fn () => UploadedFile::fake()->createWithContent('a.png', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'), 'The photo must be a PNG, JPG or WebP image.'],
    'pdf named jpg' => [fn () => UploadedFile::fake()->createWithContent('a.jpg', EF_PDF), 'The photo must be a PNG, JPG or WebP image.'],
    'html named png' => [fn () => UploadedFile::fake()->createWithContent('a.png', EF_HTML), 'The photo must be a PNG, JPG or WebP image.'],
    'too big' => [fn () => UploadedFile::fake()->image('a.png', 100, 100)->size(2049), 'The photo may not be larger than 2 MB.'],
    'too small' => [fn () => UploadedFile::fake()->image('a.png', 63, 100), 'The photo must be between 64×64 and 2000×2000 pixels.'],
    'too wide' => [fn () => UploadedFile::fake()->image('a.png', 2001, 100), 'The photo must be between 64×64 and 2000×2000 pixels.'],
    'too tall' => [fn () => UploadedFile::fake()->image('a.png', 100, 2001), 'The photo must be between 64×64 and 2000×2000 pixels.'],
    'missing' => [fn () => null, 'Choose a photo to upload.'],
    'not a file' => [fn () => 'just text', 'Choose a photo to upload.'],
]);

it('accepts the size and dimension limits exactly', function () {
    $e = efEmployee();
    $admin = efAdmin();

    $this->actingAs($admin)->post("/employees/{$e->id}/photo", ['photo' => UploadedFile::fake()->image('a.png', 64, 64)->size(2048)])->assertSessionHasNoErrors();
    $this->actingAs($admin)->post("/employees/{$e->id}/photo", ['photo' => UploadedFile::fake()->image('b.png', 2000, 64)])->assertSessionHasNoErrors();
});

it('takes the type from the content: a PNG named .jpg is stored as png', function () {
    $e = efEmployee();
    $png = UploadedFile::fake()->createWithContent('photo.jpg', efPngBytes());

    $this->actingAs(efAdmin())->post("/employees/{$e->id}/photo", ['photo' => $png])->assertSessionHasNoErrors();

    expect($e->fresh()->photo_path)->toEndWith('.png');
});

it('replacing a photo deletes the old file after saving the new one', function () {
    $e = efEmployee();
    $old = efGivePhoto($e);

    $this->actingAs(efAdmin())->post("/employees/{$e->id}/photo", ['photo' => UploadedFile::fake()->image('new.jpg', 100, 100)])->assertSessionHasNoErrors();

    $new = $e->fresh()->photo_path;
    expect($new)->not->toBe($old)
        ->and(Storage::disk('local')->exists($old))->toBeFalse()
        ->and(Storage::disk('local')->exists($new))->toBeTrue()
        ->and(efFiles("employees/{$e->id}"))->toBe([$new]);
});

it('removing a photo deletes the file and clears the column', function () {
    $e = efEmployee();
    $old = efGivePhoto($e);

    $this->actingAs(efAdmin())->delete("/employees/{$e->id}/photo")->assertSessionHas('success', 'Photo removed.');

    expect($e->fresh()->photo_path)->toBeNull()->and(Storage::disk('local')->exists($old))->toBeFalse();
});

it('removing a photo that is not there is not an error and writes no entry', function () {
    $e = efEmployee();
    $before = Activity::count();

    $this->actingAs(efAdmin())->delete("/employees/{$e->id}/photo")->assertRedirect()->assertSessionHas('info', 'There is no photo to remove.');

    expect(Activity::count())->toBe($before);
});

it('removes the new file when saving the photo fails, and keeps the old one', function () {
    $e = efEmployee();
    $old = efGivePhoto($e);
    Employee::saving(fn () => throw new RuntimeException('database down'));

    $this->withoutExceptionHandling();
    expect(fn () => $this->actingAs(efAdmin())->post("/employees/{$e->id}/photo", ['photo' => UploadedFile::fake()->image('n.png', 100, 100)]))
        ->toThrow(RuntimeException::class);

    expect(efFiles("employees/{$e->id}"))->toBe([$old])->and($e->fresh()->photo_path)->toBe($old);
});

it('serves the photo with its type, private caching and nosniff, and 404 when there is none', function () {
    $e = efEmployee();
    $admin = efAdmin();

    $this->actingAs($admin)->get("/employees/{$e->id}/photo")->assertNotFound();

    $this->actingAs($admin)->post("/employees/{$e->id}/photo", ['photo' => UploadedFile::fake()->image('a.webp', 100, 100)]);
    $response = $this->actingAs($admin)->get("/employees/{$e->id}/photo")->assertOk();

    expect($response->headers->get('Content-Type'))->toBe('image/webp')
        ->and(efDirectives($response->headers->get('Cache-Control')))->toEqualCanonicalizing(['private', 'no-cache'])
        ->and($response->headers->get('ETag'))->toMatch('/^"[0-9a-f]{40}"$/')
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($response->isRedirect())->toBeFalse()
        ->and((string) $response->headers->get('Content-Disposition'))->not->toContain('attachment');
});

it('answers 304 to a matching If-None-Match and 200 with the file once the photo changes', function () {
    $e = efEmployee();
    $admin = efAdmin();
    $this->actingAs($admin)->post("/employees/{$e->id}/photo", ['photo' => UploadedFile::fake()->image('a.png', 100, 100)]);

    $etag = $this->actingAs($admin)->get("/employees/{$e->id}/photo")->assertOk()->headers->get('ETag');

    $same = $this->actingAs($admin)->get("/employees/{$e->id}/photo", ['If-None-Match' => $etag])->assertStatus(304);
    expect($same->getContent())->toBe('')
        ->and($same->headers->get('ETag'))->toBe($etag)
        ->and($same->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and(efDirectives($same->headers->get('Cache-Control')))->toEqualCanonicalizing(['private', 'no-cache']);

    $this->actingAs($admin)->get("/employees/{$e->id}/photo", ['If-None-Match' => '"stale"'])->assertOk();

    $this->actingAs($admin)->post("/employees/{$e->id}/photo", ['photo' => UploadedFile::fake()->image('b.png', 100, 100)]);
    $this->actingAs($admin)->get("/employees/{$e->id}/photo", ['If-None-Match' => $etag])->assertOk();
});

it('checks the permission before honouring If-None-Match', function () {
    [$stranger, $target] = efCase('employee -> other');
    $etag = $this->actingAs(efAdmin())->get("/employees/{$target->id}/photo")->assertOk()->headers->get('ETag');

    $this->actingAs($stranger)->get("/employees/{$target->id}/photo", ['If-None-Match' => $etag])->assertForbidden();
});

it('strips EXIF data when re-encoding a JPEG photo and keeps the dimensions', function () {
    $e = efEmployee();
    $gd = imagecreatetruecolor(120, 90);
    ob_start();
    imagejpeg($gd);
    $jpeg = (string) ob_get_clean();
    $exif = "Exif\0\0MM\0*\0\0\0\x08\0\0GPS-LATITUDE-SECRET";
    $withExif = substr($jpeg, 0, 2)."\xFF\xE1".pack('n', strlen($exif) + 2).$exif.substr($jpeg, 2);
    expect(str_contains($withExif, 'Exif'))->toBeTrue();

    $this->actingAs(efAdmin())->post("/employees/{$e->id}/photo", ['photo' => UploadedFile::fake()->createWithContent('me.jpg', $withExif)])->assertSessionHasNoErrors();

    $stored = Storage::disk('local')->get($e->fresh()->photo_path);
    expect($stored)->not->toContain('Exif')->not->toContain('GPS-LATITUDE-SECRET');
    $size = getimagesizefromstring($stored);
    expect($size[2])->toBe(IMAGETYPE_JPEG)->and([$size[0], $size[1]])->toBe([120, 90]);
});

it('keeps a PNG photo a valid PNG with its transparency and size', function () {
    $e = efEmployee();
    $gd = imagecreatetruecolor(100, 80);
    imagealphablending($gd, false);
    imagesavealpha($gd, true);
    imagefill($gd, 0, 0, imagecolorallocatealpha($gd, 10, 200, 30, 64));
    ob_start();
    imagepng($gd);
    $png = (string) ob_get_clean();

    $this->actingAs(efAdmin())->post("/employees/{$e->id}/photo", ['photo' => UploadedFile::fake()->createWithContent('me.png', $png)])->assertSessionHasNoErrors();

    $stored = Storage::disk('local')->get($e->fresh()->photo_path);
    $size = getimagesizefromstring($stored);
    expect($size[2])->toBe(IMAGETYPE_PNG)->and([$size[0], $size[1]])->toBe([100, 80])
        // PNG colour type 6 = truecolour with alpha.
        ->and(ord($stored[25]))->toBe(6);
    $alpha = (imagecolorat(imagecreatefromstring($stored), 5, 5) >> 24) & 0x7F;
    expect($alpha)->toBeGreaterThan(0);
});

it('never serves a stored photo path that points outside the employee folder', function (callable $path) {
    $e = efEmployee();
    $other = efEmployee();
    $stray = $path($other);
    try {
        Storage::disk('local')->put($stray, 'x');
    } catch (Throwable) {
        // a traversal path cannot even be written; the column still holds it
    }
    $e->forceFill(['photo_path' => $stray])->saveQuietly();

    $this->actingAs(efAdmin())->get("/employees/{$e->id}/photo")->assertNotFound();
})->with([
    'another employee' => [fn (Employee $o) => "employees/{$o->id}/photo/".Str::random(40).'.png'],
    'traversal' => [fn (Employee $o) => 'employees/'.$o->id.'/photo/../../../.env'],
    'a document' => [fn (Employee $o) => "employees/{$o->id}/documents/".Str::random(40).'.pdf'],
    'branding' => [fn (Employee $o) => 'branding/'.Str::random(40).'.png'],
]);

function efDirectives(?string $header): array
{
    return array_map('trim', explode(',', (string) $header));
}

// ---------------------------------------------------------------- documents

it('stores a document under a random name with its details, and the uploader', function (callable $file, string $ext, string $mime) {
    $e = efEmployee();
    $admin = efAdmin();
    $upload = $file();

    $this->actingAs($admin)->post("/employees/{$e->id}/documents", [
        'type' => 'contract', 'title' => "  Signed \t contract  ", 'file' => $upload, 'expiry_date' => '2030-01-31',
    ])->assertSessionHasNoErrors()->assertSessionHas('success', 'Document added.');

    $doc = $e->documents()->sole();
    expect($doc->file_path)->toMatch('#^employees/'.$e->id.'/documents/[A-Za-z0-9]{40}\.'.$ext.'$#')
        ->and(Storage::disk('local')->exists($doc->file_path))->toBeTrue()
        ->and($doc->title)->toBe('Signed contract')
        ->and($doc->type)->toBe('contract')
        ->and($doc->original_name)->toBe($upload->getClientOriginalName())
        ->and($doc->mime)->toBe($mime)
        ->and($doc->size_bytes)->toBe($upload->getSize())
        ->and($doc->expiry_date->format('Y-m-d'))->toBe('2030-01-31')
        ->and($doc->uploaded_by)->toBe($admin->id);
})->with([
    'pdf' => [fn () => efPdf(), 'pdf', 'application/pdf'],
    'docx' => [fn () => efDocx(), 'docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
    'doc' => [fn () => efDoc(), 'doc', 'application/msword'],
    'png' => [fn () => UploadedFile::fake()->image('cnic-front.png', 300, 200), 'png', 'image/png'],
    'jpeg' => [fn () => UploadedFile::fake()->image('cnic-back.jpeg', 300, 200), 'jpg', 'image/jpeg'],
    'webp' => [fn () => UploadedFile::fake()->image('cert.webp', 300, 200), 'webp', 'image/webp'],
]);

it('refuses a document whose content does not match an allowed type', function (callable $file) {
    $e = efEmployee();

    $response = $this->actingAs(efAdmin())->post("/employees/{$e->id}/documents", ['type' => 'contract', 'title' => 'X', 'file' => $file()]);

    $response->assertSessionHasErrors(['file' => 'The file must be a PDF, a Word document (DOC or DOCX) or a PNG, JPG or WebP image.']);
    expect($e->documents()->count())->toBe(0)->and(efFiles('employees'))->toBe([]);
    // The error never echoes what was in the file.
    expect(json_encode(session('errors')->getBag('default')->toArray()))->not->toContain('script')->not->toContain('alert');
})->with([
    'html named pdf' => [fn () => UploadedFile::fake()->createWithContent('cv.pdf', EF_HTML)],
    'script named pdf' => [fn () => UploadedFile::fake()->createWithContent('cv.pdf', "#!/bin/sh\nrm -rf /\n")],
    'php named pdf' => [fn () => UploadedFile::fake()->createWithContent('cv.pdf', "<?php system(\$_GET['c']); ?>")],
    'exe named pdf' => [fn () => UploadedFile::fake()->createWithContent('cv.pdf', "MZ\x90\x00\x03\x00\x00\x00".str_repeat("\0", 200))],
    'html' => [fn () => UploadedFile::fake()->createWithContent('page.html', EF_HTML)],
    'svg' => [fn () => UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>')],
    'svg named png' => [fn () => UploadedFile::fake()->createWithContent('logo.png', '<svg xmlns="http://www.w3.org/2000/svg"/>')],
    'pdf named docx' => [fn () => UploadedFile::fake()->createWithContent('offer.docx', EF_PDF)],
    'plain zip named docx' => [fn () => efDocx('offer.docx', word: false)],
    'pdf named doc' => [fn () => UploadedFile::fake()->createWithContent('offer.doc', EF_PDF)],
    // The OLE signature with a short unrecognised body: finfo calls it application/octet-stream, not an Office file.
    'ole signature only, not an office file' => [fn () => UploadedFile::fake()->createWithContent('setup.doc', "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1".str_repeat("\0", 24).'abc')],
    'png named pdf' => [fn () => UploadedFile::fake()->createWithContent('a.pdf', efPngBytes())],
    'gif' => [fn () => UploadedFile::fake()->image('a.gif', 80, 80)],
    'exe' => [fn () => UploadedFile::fake()->createWithContent('setup.exe', "MZ\x90\x00".str_repeat("\0", 200))],
    'no extension' => [fn () => UploadedFile::fake()->createWithContent('contract', EF_PDF)],
]);

it('validates the other document fields', function (array $overrides, string $field) {
    $e = efEmployee();

    $this->actingAs(efAdmin())->post("/employees/{$e->id}/documents", array_merge(['type' => 'contract', 'title' => 'Contract', 'file' => efPdf()], $overrides))
        ->assertSessionHasErrors($field);

    expect($e->documents()->count())->toBe(0)->and(efFiles('employees'))->toBe([]);
})->with([
    'unknown type' => [['type' => 'passport'], 'type'],
    'type missing' => [['type' => ''], 'type'],
    'type array' => [['type' => ['cnic']], 'type'],
    'title missing' => [['title' => '   '], 'title'],
    'title too long' => [['title' => str_repeat('a', 151)], 'title'],
    'title array' => [['title' => ['a']], 'title'],
    'file missing' => [['file' => null], 'file'],
    'file too big' => [['file' => UploadedFile::fake()->createWithContent('big.pdf', EF_PDF)->size(10241)], 'file'],
    'expiry not a date' => [['expiry_date' => 'soon'], 'expiry_date'],
    'expiry before 2000' => [['expiry_date' => '1999-12-31'], 'expiry_date'],
]);

it('says the document size limit in words', function () {
    $e = efEmployee();

    $this->actingAs(efAdmin())->post("/employees/{$e->id}/documents", ['type' => 'contract', 'title' => 'Big', 'file' => efPdf()->size(10241)])
        ->assertSessionHasErrors(['file' => 'The file may not be larger than 10 MB.']);
});

it('accepts a document of exactly 10 MB, a 150-character title and an expiry of 2000-01-01', function () {
    $e = efEmployee();

    $this->actingAs(efAdmin())->post("/employees/{$e->id}/documents", [
        'type' => 'certificate', 'title' => str_repeat('a', 150), 'file' => efPdf()->size(10240), 'expiry_date' => '2000-01-01',
    ])->assertSessionHasNoErrors();

    expect($e->documents()->count())->toBe(1);
});

it('keeps a clean original file name, at most 200 characters', function () {
    $e = efEmployee();
    $long = str_repeat('n', 250).'.pdf';

    $this->actingAs(efAdmin())->post("/employees/{$e->id}/documents", ['type' => 'other', 'title' => 'Long', 'file' => efPdf($long)])->assertSessionHasNoErrors();

    $name = $e->documents()->sole()->original_name;
    expect(mb_strlen($name))->toBeLessThanOrEqual(200)->and($name)->toEndWith('.pdf');
});

it('allows at most 50 documents per employee', function () {
    $e = efEmployee();
    EmployeeDocument::factory()->count(50)->create(['employee_id' => $e->id]);

    $this->actingAs(efAdmin())->post("/employees/{$e->id}/documents", ['type' => 'other', 'title' => 'One more', 'file' => efPdf()])
        ->assertSessionHasErrors(['file' => 'This employee already has 50 documents. Delete one before adding another.']);

    expect($e->documents()->count())->toBe(50)->and(efFiles('employees'))->toBe([]);
});

it('removes the stored file when the document row cannot be written', function () {
    $e = efEmployee();
    EmployeeDocument::creating(fn () => throw new RuntimeException('database down'));

    $this->withoutExceptionHandling();
    expect(fn () => $this->actingAs(efAdmin())->post("/employees/{$e->id}/documents", ['type' => 'other', 'title' => 'X', 'file' => efPdf()]))
        ->toThrow(RuntimeException::class);

    expect(efFiles('employees'))->toBe([])->and(EmployeeDocument::count())->toBe(0);
});

it('deletes a document and its file', function () {
    $e = efEmployee();
    $doc = efGiveDocument($e);

    $this->actingAs(efAdmin())->delete("/employees/{$e->id}/documents/{$doc->id}")->assertSessionHas('success', 'Document deleted.');

    expect(EmployeeDocument::find($doc->id))->toBeNull()->and(Storage::disk('local')->exists($doc->file_path))->toBeFalse();
});

it('still deletes the row when the file is already gone', function () {
    $e = efEmployee();
    $doc = efGiveDocument($e);
    Storage::disk('local')->delete($doc->file_path);

    $this->actingAs(efAdmin())->delete("/employees/{$e->id}/documents/{$doc->id}")->assertSessionHas('success', 'Document deleted.');

    expect(EmployeeDocument::find($doc->id))->toBeNull();
});

it('never deletes a file outside the employee folder, whatever the row says', function () {
    $e = efEmployee();
    $other = efEmployee();
    $kept = efGiveDocument($other);
    $doc = efGiveDocument($e, ['file_path' => $kept->file_path]);

    $this->actingAs(efAdmin())->delete("/employees/{$e->id}/documents/{$doc->id}")->assertSessionHas('success');

    expect(Storage::disk('local')->exists($kept->file_path))->toBeTrue();
});

it('answers 404 for a document of another employee, and changes nothing', function () {
    $mine = efEmployee();
    $theirs = efEmployee();
    $doc = efGiveDocument($theirs);
    $admin = efAdmin();

    $this->actingAs($admin)->get("/employees/{$mine->id}/documents/{$doc->id}")->assertNotFound();
    $this->actingAs($admin)->delete("/employees/{$mine->id}/documents/{$doc->id}")->assertNotFound();
    $this->actingAs($admin)->get("/employees/{$mine->id}/documents/999999")->assertNotFound();

    expect(EmployeeDocument::find($doc->id))->not->toBeNull()->and(Storage::disk('local')->exists($doc->file_path))->toBeTrue();
});

it('lets an employee fetch their own documents but not another person\'s through their own URL', function () {
    $user = userWithRole('employee');
    $own = employeeFor($user, efDept());
    $other = efEmployee();
    $theirs = efGiveDocument($other);

    $this->actingAs($user)->get("/employees/{$own->id}/documents/{$theirs->id}")->assertNotFound();
    $this->actingAs($user)->get("/employees/{$other->id}/documents/{$theirs->id}")->assertForbidden();
});

it('downloads a document as an attachment with its stored type and private no-store caching', function () {
    $e = efEmployee();
    $doc = efGiveDocument($e, ['original_name' => 'Ayesha contract 2024.pdf']);

    $response = $this->actingAs(efAdmin())->get("/employees/{$e->id}/documents/{$doc->id}")->assertOk();

    expect($response->headers->get('Content-Type'))->toBe('application/pdf')
        ->and($response->headers->get('Content-Disposition'))->toStartWith('attachment;')->toContain('Ayesha contract 2024.pdf')
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and(efDirectives($response->headers->get('Cache-Control')))->toContain('no-store')->toContain('private')
        ->and(file_get_contents($response->baseResponse->getFile()->getPathname()))->toBe(EF_PDF);
});

it('sanitises the download name', function (string $stored, string $expected) {
    $e = efEmployee();
    $doc = efGiveDocument($e, ['original_name' => $stored]);

    $disposition = $this->actingAs(efAdmin())->get("/employees/{$e->id}/documents/{$doc->id}")->assertOk()->headers->get('Content-Disposition');

    $expected = str_replace('{id}', (string) $doc->id, $expected);
    expect($disposition)->toMatch('/filename="?'.preg_quote($expected, '/').'"?(;|$)/')->not->toContain("\r")->not->toContain("\n");
})->with([
    'path parts' => ['../../etc/passwd.pdf', 'etcpasswd.pdf'],
    'backslashes' => ['..\\..\\windows\\x.pdf', 'windowsx.pdf'],
    'quotes and controls' => ["evil\"na'me\r\n.pdf", 'evilname.pdf'],
    'nothing left' => ['""/\\', 'document-{id}.pdf'],
    'wrong extension kept off' => ['report.html', 'report.html.pdf'],
]);

it('never serves a document whose stored path points outside the employee folder', function () {
    $e = efEmployee();
    $other = efEmployee();
    $theirs = efGiveDocument($other);
    $doc = efGiveDocument($e, ['file_path' => $theirs->file_path]);

    $this->actingAs(efAdmin())->get("/employees/{$e->id}/documents/{$doc->id}")->assertNotFound();

    $doc->update(['file_path' => "employees/{$e->id}/documents/../../{$other->id}/documents/x.pdf"]);
    $this->actingAs(efAdmin())->get("/employees/{$e->id}/documents/{$doc->id}")->assertNotFound();
});

it('answers 404 when a document file is missing from the disk', function () {
    $e = efEmployee();
    $doc = efGiveDocument($e);
    Storage::disk('local')->delete($doc->file_path);

    $this->actingAs(efAdmin())->get("/employees/{$e->id}/documents/{$doc->id}")->assertNotFound();
});

it('limits photo and document uploads together to 30 a minute per user', function () {
    $e = efEmployee();
    $admin = efAdmin();

    for ($i = 0; $i < 30; $i++) {
        $this->actingAs($admin)->post("/employees/{$e->id}/documents", [])->assertStatus(302);
    }

    $this->actingAs($admin)->post("/employees/{$e->id}/documents", [])->assertStatus(429);
    $this->actingAs($admin)->post("/employees/{$e->id}/photo", [])->assertStatus(429); // one counter for all uploads
});

// ---------------------------------------------------------------- nothing public

it('keeps every file off the public web root', function () {
    $e = efEmployee();
    $admin = efAdmin();
    $this->actingAs($admin)->post("/employees/{$e->id}/photo", ['photo' => UploadedFile::fake()->image('a.png', 100, 100)]);
    $this->actingAs($admin)->post("/employees/{$e->id}/documents", ['type' => 'other', 'title' => 'X', 'file' => efPdf()]);

    $photo = $e->fresh()->photo_path;
    $doc = $e->documents()->sole()->file_path;

    // The disk the app uses is the private one, which has no public URL, and it lives outside public/.
    expect(config('filesystems.disks.local.serve'))->toBeFalse()
        ->and(str_starts_with(str_replace('\\', '/', config('filesystems.disks.local.root')), str_replace('\\', '/', public_path())))->toBeFalse();

    $public = collect(File::allFiles(public_path()))->map(fn ($f) => $f->getFilename())->all();
    expect($public)->not->toContain(basename($photo))->not->toContain(basename($doc));

    $this->actingAs($admin)->get('/storage/'.$photo)->assertNotFound();
    $this->actingAs($admin)->get('/storage/'.$doc)->assertNotFound();
    $this->actingAs($admin)->get('/'.$doc)->assertNotFound();
});

// ---------------------------------------------------------------- audit

it('records photo changes as facts, without paths', function () {
    $e = efEmployee();
    $admin = efAdmin();

    $this->actingAs($admin)->post("/employees/{$e->id}/photo", ['photo' => UploadedFile::fake()->image('a.png', 100, 100)]);
    $first = $e->fresh()->photo_path;
    $this->actingAs($admin)->post("/employees/{$e->id}/photo", ['photo' => UploadedFile::fake()->image('b.png', 100, 100)]);
    $second = $e->fresh()->photo_path;
    $this->actingAs($admin)->delete("/employees/{$e->id}/photo");

    $entries = Activity::where('log_name', 'employees')->where('subject_id', $e->id)->where('subject_type', $e->getMorphClass())
        ->where('description', 'like', 'Employee photo%')->orderBy('id')->get();

    expect($entries->pluck('description')->all())->toBe(['Employee photo added', 'Employee photo changed', 'Employee photo removed'])
        ->and($entries->pluck('causer_id')->unique()->all())->toBe([$admin->id])
        ->and($entries[0]->properties['attributes'])->toBe(['photo' => '[added]'])
        ->and($entries[1]->properties['attributes'])->toBe(['photo' => '[replaced]'])
        ->and($entries[2]->properties['attributes'])->toBe(['photo' => '[removed]']);

    $json = json_encode(Activity::all()->pluck('properties'));
    expect($json)->not->toContain(basename($first))->not->toContain(basename($second))->not->toContain('employees/'.$e->id)
        ->and(Activity::where('description', 'Employee updated')->count())->toBe(0);
});

it('records a document added and removed with title and type only', function () {
    $e = efEmployee();
    $admin = efAdmin();

    $this->actingAs($admin)->post("/employees/{$e->id}/documents", ['type' => 'cnic', 'title' => 'CNIC front', 'file' => efPdf('my-cnic-scan.pdf')]);
    $doc = $e->documents()->sole();
    $this->actingAs($admin)->delete("/employees/{$e->id}/documents/{$doc->id}");

    $entries = Activity::where('log_name', 'employees')->where('description', 'like', 'Employee document%')->orderBy('id')->get();

    expect($entries->pluck('description')->all())->toBe(['Employee document added', 'Employee document removed'])
        ->and($entries->pluck('subject_id')->unique()->all())->toBe([$e->id])
        ->and($entries->pluck('causer_id')->unique()->all())->toBe([$admin->id])
        ->and($entries[0]->properties['attributes'])->toBe(['type' => 'cnic', 'title' => 'CNIC front'])
        ->and($entries[1]->properties['old'])->toBe(['type' => 'cnic', 'title' => 'CNIC front']);

    $json = json_encode(Activity::all()->pluck('properties'));
    expect($json)->not->toContain(basename($doc->file_path))->not->toContain('my-cnic-scan')->not->toContain('%PDF')->not->toContain('employees/'.$e->id);
});

it('writes nothing to the audit log when an upload is refused', function () {
    $e = efEmployee();
    $admin = efAdmin();
    $before = Activity::count();

    $this->actingAs($admin)->post("/employees/{$e->id}/documents", ['type' => 'cnic', 'title' => 'X', 'file' => UploadedFile::fake()->createWithContent('x.pdf', EF_HTML)]);
    $this->actingAs($admin)->post("/employees/{$e->id}/photo", ['photo' => UploadedFile::fake()->image('a.gif', 100, 100)]);

    expect(Activity::count())->toBe($before);
});

it('strips bidi and invisible characters from a stored file name', function () {
    expect(EmployeeFiles::cleanName("invoice\u{202E}fdp.exe"))->toBe('invoicefdp.exe')
        ->and(EmployeeFiles::cleanName("a\u{200B}b\u{200F}c\u{2066}d\u{2069}e\u{FEFF}.pdf"))->toBe('abcde.pdf');

    $e = efEmployee();
    $this->actingAs(efAdmin())->post("/employees/{$e->id}/documents", ['type' => 'other', 'title' => 'RTL', 'file' => efPdf("report\u{202E}fdp.pdf")])->assertSessionHasNoErrors();

    expect($e->documents()->sole()->original_name)->toBe('reportfdp.pdf');
});

// ---------------------------------------------------------------- deleting an employee

it('still deletes the employee when removing the files folder fails, and reports it', function () {
    Exceptions::fake();
    $e = efEmployee();
    $disk = Mockery::mock(Filesystem::class);
    $disk->shouldReceive('deleteDirectory')->once()->andThrow(new RuntimeException('disk gone'));
    Storage::shouldReceive('disk')->with('local')->andReturn($disk);

    $this->actingAs(efAdmin())->delete("/employees/{$e->id}")->assertSessionHas('success', 'Employee deleted.');

    expect(Employee::find($e->id))->toBeNull();
    Exceptions::assertReported(fn (RuntimeException $x) => $x->getMessage() === 'disk gone');
});

it('deleting an employee removes their files folder and nobody else\'s', function () {
    $e = efEmployee();
    $other = efEmployee();
    efGivePhoto($e);
    efGiveDocument($e);
    $kept = efGivePhoto($other);

    $this->actingAs(efAdmin())->delete("/employees/{$e->id}")->assertSessionHas('success', 'Employee deleted.');

    expect(Storage::disk('local')->directoryExists("employees/{$e->id}"))->toBeFalse()
        ->and(efFiles("employees/{$e->id}"))->toBe([])
        ->and(Storage::disk('local')->exists($kept))->toBeTrue();
});

it('keeps the files when the employee cannot be deleted', function () {
    $e = efEmployee();
    efEmployee(['manager_id' => $e->id]);
    $photo = efGivePhoto($e);

    $this->actingAs(efAdmin())->delete("/employees/{$e->id}")->assertSessionHas('error');

    expect(Storage::disk('local')->exists($photo))->toBeTrue();
});

// ---------------------------------------------------------------- photo_url and the documents props

it('gives the list a photo URL with a version, or null', function () {
    $with = efEmployee(['first_name' => 'Aaa']);
    $without = efEmployee(['first_name' => 'Bbb']);
    efGivePhoto($with);
    $with->refresh();

    $rows = collect($this->actingAs(efAdmin())->get('/employees')->assertOk()->viewData('page')['props']['employees']['data'])->keyBy('id');

    expect($rows[$with->id]['photo_url'])->toBe("/employees/{$with->id}/photo?v=".$with->updated_at->getTimestamp())
        ->and($rows[$without->id]['photo_url'])->toBeNull();
});

it('gives the profile and the edit page a photo URL, and a manager the report\'s photo URL', function () {
    $user = userWithRole('manager');
    $own = employeeFor($user, efDept());
    $report = efEmployee(['manager_id' => $own->id]);
    efGivePhoto($report);
    $report->refresh();
    $url = "/employees/{$report->id}/photo?v=".$report->updated_at->getTimestamp();

    $asManager = $this->actingAs($user)->get("/employees/{$report->id}")->assertOk()->viewData('page')['props'];
    $edit = $this->actingAs(efAdmin())->get("/employees/{$report->id}/edit")->assertOk()->viewData('page')['props'];

    expect($asManager['employee']['photo_url'])->toBe($url)
        ->and($edit['employee']['photo_url'])->toBe($url);

    // The URL works for the manager.
    $this->actingAs($user)->get($url)->assertOk();
});

it('changes the photo URL when the photo changes', function () {
    $e = efEmployee();
    $admin = efAdmin();
    $this->travelTo(now()->subMinute());
    $this->actingAs($admin)->post("/employees/{$e->id}/photo", ['photo' => UploadedFile::fake()->image('a.png', 100, 100)]);
    $this->travelBack();
    $first = $this->actingAs($admin)->get("/employees/{$e->id}")->viewData('page')['props']['employee']['photo_url'];

    $this->actingAs($admin)->post("/employees/{$e->id}/photo", ['photo' => UploadedFile::fake()->image('b.png', 100, 100)]);
    $second = $this->actingAs($admin)->get("/employees/{$e->id}")->viewData('page')['props']['employee']['photo_url'];

    expect($first)->not->toBeNull()->and($second)->not->toBe($first);
});

it('sends the profile the documents list, never a stored path', function () {
    $e = efEmployee();
    $doc = efGiveDocument($e, ['type' => 'cnic', 'title' => 'CNIC', 'original_name' => 'cnic.pdf', 'expiry_date' => '2031-05-01']);

    $props = $this->actingAs(efAdmin())->get("/employees/{$e->id}")->viewData('page')['props'];

    expect($props['employee']['documents']['items'])->toBe([[
        'id' => $doc->id,
        'type' => 'cnic',
        'title' => 'CNIC',
        'original_name' => 'cnic.pdf',
        'size_bytes' => strlen(EF_PDF),
        'uploaded_at' => $doc->created_at->format('Y-m-d'),
        'expiry_date' => '2031-05-01',
        'download_url' => "/employees/{$e->id}/documents/{$doc->id}",
    ]])
        ->and($props['documentTypes'])->toBe(config('hrm.employees.document_types'))
        ->and(json_encode($props))->not->toContain(basename($doc->file_path))->not->toContain('file_path');
});

it('gives the edit page the documents list and the types', function () {
    $e = efEmployee();
    $doc = efGiveDocument($e);

    $props = $this->actingAs(efAdmin())->get("/employees/{$e->id}/edit")->assertOk()->viewData('page')['props'];

    expect($props['documents'])->toHaveCount(1)
        ->and($props['documents'][0]['id'])->toBe($doc->id)
        ->and($props['documents'][0]['download_url'])->toBe("/employees/{$e->id}/documents/{$doc->id}")
        ->and($props['documentTypes'])->toBe(config('hrm.employees.document_types'))
        ->and(json_encode($props))->not->toContain(basename($doc->file_path));

    $create = $this->actingAs(efAdmin())->get('/employees/create')->assertOk()->viewData('page')['props'];
    expect($create['documents'])->toBe([]);
});

it('gives a manager no documents of a report, not even in the props', function () {
    $user = userWithRole('manager');
    $own = employeeFor($user, efDept());
    $report = efEmployee(['manager_id' => $own->id]);
    $doc = efGiveDocument($report, ['title' => 'Hidden Report Contract']);

    $props = $this->actingAs($user)->get("/employees/{$report->id}")->assertOk()->viewData('page')['props'];

    expect($props['employee'])->not->toHaveKey('documents')
        ->and(json_encode($props))->not->toContain('Hidden Report Contract')->not->toContain("/documents/{$doc->id}");
});

// ---------------------------------------------------------------- carried fixes

it('refuses HR re-posting an Admin person\'s current status instead of saying "No change."', function () {
    $adminUser = userWithRole('admin');
    $person = employeeFor($adminUser, efDept());

    $this->actingAs(userWithRole('hr'))->patch("/employees/{$person->id}/status", ['status' => $person->status])
        ->assertSessionHas('error', EmployeeRoles::PRIVILEGED_MESSAGE)
        ->assertSessionMissing('info');
});

it('shares the more privileged role of a two-role account as auth.role', function () {
    test()->seed(RolePermissionSeeder::class);
    // Give "admin" a higher id than "employee", so the first role by id is the less privileged one.
    Role::findByName('admin')->delete();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    test()->seed(RolePermissionSeeder::class);

    $user = User::factory()->create();
    $user->assignRole('employee');
    $user->assignRole('admin');
    expect($user->getRoleNames()->first())->toBe('employee'); // what the old code shared

    $this->actingAs($user->refresh())->get('/dashboard')
        ->assertInertia(fn ($p) => $p->where('auth.role', 'admin'));
});

// ---------------------------------------------------------------- JPEG orientation (without the exif extension)

/** The payload of an APP1 segment: "Exif\0\0" then a TIFF header and IFD0 with one Orientation (0x0112) entry. */
function efExifPayload(int $orientation, bool $littleEndian = true): string
{
    $u16 = fn (int $v) => pack($littleEndian ? 'v' : 'n', $v);
    $u32 = fn (int $v) => pack($littleEndian ? 'V' : 'N', $v);

    $tiff = ($littleEndian ? 'II' : 'MM').$u16(42).$u32(8)
        .$u16(1)                                            // one entry
        .$u16(0x0112).$u16(3).$u32(1).$u16($orientation).$u16(0) // Orientation, SHORT, count 1, value
        .$u32(0);                                           // no next IFD

    return "Exif\0\0".$tiff;
}

/** A real JPEG of $w x $h (left half red, right half blue) with an APP1 segment holding $payload right after SOI. */
function efJpegWithApp1(?string $payload, int $w = 120, int $h = 90): string
{
    $gd = imagecreatetruecolor($w, $h);
    imagefilledrectangle($gd, 0, 0, intdiv($w, 2) - 1, $h - 1, imagecolorallocate($gd, 255, 0, 0));
    imagefilledrectangle($gd, intdiv($w, 2), 0, $w - 1, $h - 1, imagecolorallocate($gd, 0, 0, 255));
    ob_start();
    imagejpeg($gd, null, 95);
    $jpeg = (string) ob_get_clean();

    if ($payload === null) {
        return $jpeg;
    }

    return substr($jpeg, 0, 2)."\xFF\xE1".pack('n', strlen($payload) + 2).$payload.substr($jpeg, 2);
}

/** Uploads $bytes as a JPG photo and returns the stored bytes. */
function efUploadJpeg(string $bytes): string
{
    $e = efEmployee();
    test()->actingAs(efAdmin())->post("/employees/{$e->id}/photo", ['photo' => UploadedFile::fake()->createWithContent('phone.jpg', $bytes)])
        ->assertSessionHasNoErrors();

    return Storage::disk('local')->get($e->fresh()->photo_path);
}

it('turns a JPEG photo upright from its EXIF orientation and drops the EXIF', function (bool $littleEndian) {
    $stored = efUploadJpeg(efJpegWithApp1(efExifPayload(6, $littleEndian)));

    $size = getimagesizefromstring($stored);
    // Orientation 6 = turn 90° clockwise: 120x90 becomes 90x120.
    expect([$size[0], $size[1]])->toBe([90, 120])
        ->and($stored)->not->toContain('Exif');

    // The red (left) half of the upload is now on top.
    $img = imagecreatefromstring($stored);
    $top = imagecolorsforindex($img, imagecolorat($img, 45, 10));
    $bottom = imagecolorsforindex($img, imagecolorat($img, 45, 110));
    expect($top['red'])->toBeGreaterThan(200)->and($top['blue'])->toBeLessThan(60)
        ->and($bottom['blue'])->toBeGreaterThan(200)->and($bottom['red'])->toBeLessThan(60);
})->with(['little-endian (II)' => true, 'big-endian (MM)' => false]);

it('leaves a JPEG photo as it is for orientation 1 or no EXIF', function (?string $payload) {
    $stored = efUploadJpeg(efJpegWithApp1($payload));

    $size = getimagesizefromstring($stored);
    expect([$size[0], $size[1]])->toBe([120, 90])->and($stored)->not->toContain('Exif');
})->with([
    'orientation 1' => [efExifPayload(1)],
    'no EXIF' => [null],
]);

it('accepts a JPEG photo whose EXIF segment is garbage or cut short, as having no orientation', function (string $payload) {
    $stored = efUploadJpeg(efJpegWithApp1($payload));

    $size = getimagesizefromstring($stored);
    expect([$size[0], $size[1]])->toBe([120, 90])->and($stored)->not->toContain('Exif');
})->with([
    'cut short' => [substr(efExifPayload(6), 0, 14)],
    'random bytes' => ["Exif\0\0".str_repeat("\xA7\x13\xFF", 20)],
    'unknown byte order' => ["Exif\0\0XX\0*\0\0\0\x08".str_repeat("\0", 20)],
]);

describe('EmployeeFiles::jpegOrientation (the reader used when exif_read_data is missing)', function () {
    it('reads the orientation in both byte orders', function (int $orientation, bool $littleEndian) {
        expect(EmployeeFiles::jpegOrientation(efJpegWithApp1(efExifPayload($orientation, $littleEndian), 16, 16)))->toBe($orientation);
    })->with([1, 2, 3, 4, 5, 6, 7, 8])->with(['II' => true, 'MM' => false]);

    it('finds the EXIF segment after other segments', function () {
        // GD writes an APP0 (JFIF) segment; put the EXIF one after it.
        $jpeg = efJpegWithApp1(null, 16, 16);
        $app0Length = unpack('n', substr($jpeg, 4, 2))[1];
        $payload = efExifPayload(8, false);
        $at = 4 + $app0Length;
        $bytes = substr($jpeg, 0, $at)."\xFF\xE1".pack('n', strlen($payload) + 2).$payload.substr($jpeg, $at);

        expect(substr($jpeg, 2, 2))->toBe("\xFF\xE0")
            ->and(EmployeeFiles::jpegOrientation($bytes))->toBe(8);
    });

    it('gives null for anything it cannot trust', function (string $bytes) {
        expect(EmployeeFiles::jpegOrientation($bytes))->toBeNull();
    })->with([
        'empty' => [''],
        'not a JPEG' => [efPngBytes()],
        'no EXIF' => [efJpegWithApp1(null, 16, 16)],
        'orientation 0' => [efJpegWithApp1(efExifPayload(0), 16, 16)],
        'orientation 9' => [efJpegWithApp1(efExifPayload(9), 16, 16)],
        'IFD offset past the end' => [efJpegWithApp1("Exif\0\0II*\0".pack('V', 0xFFFFFFF0).str_repeat("\0", 20), 16, 16)],
        'entry count past the end' => [efJpegWithApp1("Exif\0\0II*\0".pack('V', 8).pack('v', 0xFFFF).str_repeat("\0", 30), 16, 16)],
        'segment length past the end' => ["\xFF\xD8\xFF\xE1\xFF\xFFExif\0\0II*\0"],
        'segment length below 2' => ["\xFF\xD8\xFF\xE1\x00\x01Exif\0\0II*\0\x08\0\0\0"],
        'only SOI' => ["\xFF\xD8"],
    ]);

    it('does not read more than a capped number of IFD entries', function () {
        // 300 harmless entries, then the Orientation entry: past the cap, so it is not reached.
        $entries = str_repeat(pack('v', 0x010F).pack('v', 2).pack('V', 1).pack('V', 0), 300)
            .pack('v', 0x0112).pack('v', 3).pack('V', 1).pack('v', 6).pack('v', 0);
        $payload = "Exif\0\0II*\0".pack('V', 8).pack('v', 301).$entries.pack('V', 0);

        expect(EmployeeFiles::jpegOrientation(efJpegWithApp1($payload, 16, 16)))->toBeNull();
    });
});

it('turns and flips an image for each EXIF orientation', function (?int $orientation, array $size, array $marker) {
    // 4x2, black, with one white pixel at the top-left corner.
    $gd = imagecreatetruecolor(4, 2);
    imagesetpixel($gd, 0, 0, imagecolorallocate($gd, 255, 255, 255));

    $out = EmployeeFiles::applyOrientation($gd, $orientation);

    expect([imagesx($out), imagesy($out)])->toBe($size)
        ->and(imagecolorat($out, $marker[0], $marker[1]) & 0xFFFFFF)->toBe(0xFFFFFF);
})->with([
    'null' => [null, [4, 2], [0, 0]],
    '1' => [1, [4, 2], [0, 0]],
    '2 mirror' => [2, [4, 2], [3, 0]],
    '3 half turn' => [3, [4, 2], [3, 1]],
    '4 flip' => [4, [4, 2], [0, 1]],
    '5 transpose' => [5, [2, 4], [0, 0]],
    '6 clockwise' => [6, [2, 4], [1, 0]],
    '7 transverse' => [7, [2, 4], [1, 3]],
    '8 anticlockwise' => [8, [2, 4], [0, 3]],
]);
