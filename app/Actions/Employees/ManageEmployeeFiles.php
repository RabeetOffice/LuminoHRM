<?php

namespace App\Actions\Employees;

use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\EmployeeFiles;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Writes for an employee's photo and documents. Each database change is one transaction with its audit entry
 * (area `employees`: the photo as a fact, a document by title and type, never a path, a file name or contents).
 *
 * Files and rows never drift apart: a new file is stored first and deleted again if the transaction fails; an old
 * file is deleted only after the transaction that stopped pointing at it has committed (a file already gone is fine).
 * The caller has checked the permission (EmployeeFiles::mayManage) and validated the upload.
 */
class ManageEmployeeFiles
{
    public const CAP_MESSAGE = 'This employee already has 50 documents. Delete one before adding another.';

    public function replacePhoto(Employee $employee, UploadedFile $file, string $extension, User $by): void
    {
        $path = EmployeeFiles::storePhoto($employee, $file, $extension);

        try {
            $old = DB::transaction(function () use ($employee, $path, $by): ?string {
                $current = Employee::query()->whereKey($employee->getKey())->lockForUpdate()->firstOrFail();
                $old = $current->photo_path;

                $current->disableLogging(); // the entry below is the record of this change
                $current->photo_path = $path;
                $current->save();

                Audit::record('employees', $old === null ? 'Employee photo added' : 'Employee photo changed', $current, [
                    'attributes' => ['photo' => $old === null ? '[added]' : '[replaced]'],
                ], $by);

                return $old;
            }, attempts: 3);
        } catch (Throwable $e) {
            EmployeeFiles::deletePhoto($employee->id, $path);

            throw $e;
        }

        EmployeeFiles::deletePhoto($employee->id, $old);
    }

    /** Returns false when there was no photo (nothing written). */
    public function removePhoto(Employee $employee, User $by): bool
    {
        $old = DB::transaction(function () use ($employee, $by): ?string {
            $current = Employee::query()->whereKey($employee->getKey())->lockForUpdate()->firstOrFail();
            $old = $current->photo_path;

            if ($old === null) {
                return null;
            }

            $current->disableLogging();
            $current->photo_path = null;
            $current->save();

            Audit::record('employees', 'Employee photo removed', $current, ['attributes' => ['photo' => '[removed]']], $by);

            return $old;
        }, attempts: 3);

        if ($old === null) {
            return false;
        }

        EmployeeFiles::deletePhoto($employee->id, $old);

        return true;
    }

    /**
     * @param  array{type: string, title: string, expiry_date?: string|null}  $data
     *
     * @throws ValidationException the employee already has the maximum number of documents
     */
    public function addDocument(Employee $employee, array $data, UploadedFile $file, string $extension, User $by): EmployeeDocument
    {
        // Cheap early answer; the count is checked again under the lock below.
        if ($employee->documents()->count() >= EmployeeFiles::MAX_DOCUMENTS) {
            throw ValidationException::withMessages(['file' => self::CAP_MESSAGE]);
        }

        $path = EmployeeFiles::storeDocument($employee, $file, $extension);
        $original = EmployeeFiles::cleanName($file->getClientOriginalName());

        try {
            return DB::transaction(function () use ($employee, $data, $file, $extension, $path, $original, $by): EmployeeDocument {
                $current = Employee::query()->whereKey($employee->getKey())->lockForUpdate()->firstOrFail();

                if ($current->documents()->count() >= EmployeeFiles::MAX_DOCUMENTS) {
                    throw ValidationException::withMessages(['file' => self::CAP_MESSAGE]);
                }

                $document = new EmployeeDocument([
                    'employee_id' => $current->id,
                    'type' => $data['type'],
                    'title' => $data['title'],
                    'file_path' => $path,
                    'original_name' => $original !== '' ? $original : "document.{$extension}",
                    'mime' => EmployeeFiles::DOCUMENT_MIMES[$extension],
                    'size_bytes' => (int) $file->getSize(),
                    'expiry_date' => $data['expiry_date'] ?? null,
                    'uploaded_by' => $by->id,
                ]);
                $document->disableLogging(); // the entry below is the record of this change
                $document->save();

                Audit::record('employees', 'Employee document added', $current, [
                    'attributes' => ['type' => $document->type, 'title' => $document->title],
                ], $by);

                return $document;
            }, attempts: 3);
        } catch (Throwable $e) {
            EmployeeFiles::deleteDocument($employee->id, $path);

            throw $e;
        }
    }

    public function removeDocument(Employee $employee, EmployeeDocument $document, User $by): void
    {
        $path = DB::transaction(function () use ($employee, $document, $by): string {
            $current = EmployeeDocument::query()
                ->whereKey($document->getKey())
                ->where('employee_id', $employee->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $current->disableLogging();
            $current->delete();

            Audit::record('employees', 'Employee document removed', $employee, [
                'old' => ['type' => $current->type, 'title' => $current->title],
            ], $by);

            return $current->file_path;
        }, attempts: 3);

        EmployeeFiles::deleteDocument($employee->id, $path);
    }
}
