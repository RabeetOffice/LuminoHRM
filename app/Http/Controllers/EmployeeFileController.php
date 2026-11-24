<?php

namespace App\Http\Controllers;

use App\Actions\Employees\ManageEmployeeFiles;
use App\Http\Requests\EmployeeDocumentRequest;
use App\Http\Requests\EmployeePhotoRequest;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Support\EmployeeAccess;
use App\Support\EmployeeFiles;
use App\Support\PhotoNotProcessable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * An employee's photo and documents. Files live on the private disk (see EmployeeFiles) and leave it only through
 * these actions, each of which asks EmployeeAccess first:
 *
 * - the photo: anyone who may view the employee (a Manager their direct reports, everyone their own record);
 * - a document: whoever has the `documents` field group (Admin, HR, the employee themself), never a Manager;
 * - adding, replacing or removing: the rule for editing the record (EmployeeFiles::mayManage).
 *
 * A document id that belongs to another employee than the one in the URL is a 404, and a stored path is used only
 * when it lies in that employee's own folder.
 */
class EmployeeFileController extends Controller
{
    public function showPhoto(Request $request, Employee $employee): BinaryFileResponse|Response
    {
        abort_unless((new EmployeeAccess($request->user()))->canView($employee), 403);

        $path = $employee->photo_path;

        abort_unless(EmployeeFiles::isPhotoPath($employee->id, $path) && EmployeeFiles::disk()->exists($path), 404);

        // A strong ETag from the stored path and its modified time. The permission check above has already run.
        $etag = '"'.sha1($path.'|'.EmployeeFiles::disk()->lastModified($path)).'"';
        $headers = [
            'ETag' => $etag,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ];

        if (in_array($etag, array_map('trim', explode(',', (string) $request->header('If-None-Match'))), true)) {
            return response('', 304, $headers + ['Cache-Control' => 'private, no-cache']);
        }

        $response = response()->file(EmployeeFiles::disk()->path($path), $headers + [
            'Content-Type' => EmployeeFiles::PHOTO_CONTENT_TYPES[pathinfo($path, PATHINFO_EXTENSION)],
        ]);

        // Personal data: the browser may keep it but must revalidate (a 304 costs no body), a shared cache may not keep it.
        // Set after construction, because a file response makes itself public by default.
        $response->headers->set('Cache-Control', 'private, no-cache');

        return $response;
    }

    public function updatePhoto(EmployeePhotoRequest $request, Employee $employee, ManageEmployeeFiles $files): RedirectResponse
    {
        $file = $request->file('photo');

        try {
            $files->replacePhoto($employee, $file, (string) EmployeeFiles::photoExtension($file), $request->user());
        } catch (PhotoNotProcessable) {
            // Raised before anything is stored or written, so the previous photo is untouched.
            throw ValidationException::withMessages(['photo' => EmployeePhotoRequest::UNPROCESSABLE_MESSAGE]);
        }

        return back()->with('success', 'Photo updated.');
    }

    public function destroyPhoto(Request $request, Employee $employee, ManageEmployeeFiles $files): RedirectResponse
    {
        abort_unless(EmployeeFiles::mayManage($request->user(), $employee), 403);

        return $files->removePhoto($employee, $request->user())
            ? back()->with('success', 'Photo removed.')
            : back()->with('info', 'There is no photo to remove.');
    }

    public function storeDocument(EmployeeDocumentRequest $request, Employee $employee, ManageEmployeeFiles $files): RedirectResponse
    {
        $file = $request->file('file');

        $files->addDocument(
            $employee,
            [
                'type' => (string) $request->validated('type'),
                'title' => (string) $request->validated('title'),
                'expiry_date' => $request->validated('expiry_date'),
            ],
            $file,
            (string) EmployeeFiles::documentExtension($file),
            $request->user(),
        );

        return back()->with('success', 'Document added.');
    }

    public function downloadDocument(Request $request, Employee $employee, string $document): BinaryFileResponse
    {
        abort_unless(in_array('documents', (new EmployeeAccess($request->user()))->fieldGroups($employee), true), 403);

        $row = $this->document($employee, $document);
        $path = $row->file_path;

        abort_unless(EmployeeFiles::isDocumentPath($employee->id, $path) && EmployeeFiles::disk()->exists($path), 404);

        $extension = pathinfo($path, PATHINFO_EXTENSION);

        $response = response()->download(EmployeeFiles::disk()->path($path), EmployeeFiles::downloadName($row, $extension), [
            'Content-Type' => EmployeeFiles::DOCUMENT_MIMES[$extension],
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);

        // Never kept by any cache (set after construction: a file response makes itself public by default).
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    public function destroyDocument(Request $request, Employee $employee, string $document, ManageEmployeeFiles $files): RedirectResponse
    {
        abort_unless(EmployeeFiles::mayManage($request->user(), $employee), 403);

        $files->removeDocument($employee, $this->document($employee, $document), $request->user());

        return back()->with('success', 'Document deleted.');
    }

    /** The document only if it belongs to THIS employee; any other id is a 404 (asked after the access check, so it reveals nothing). */
    private function document(Employee $employee, string $id): EmployeeDocument
    {
        abort_unless(ctype_digit($id) && strlen($id) <= 18, 404);

        return EmployeeDocument::query()->where('employee_id', $employee->getKey())->whereKey((int) $id)->firstOr(fn () => abort(404));
    }
}
