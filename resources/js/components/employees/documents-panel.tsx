import { router, useForm, usePage } from '@inertiajs/react';
import { Download, FileText, Plus, Trash2 } from 'lucide-react';
import { useEffect, useState } from 'react';
import type { FormEvent } from 'react';
import { ConfirmDialog } from '@/components/hrm/confirm-dialog';
import { StatusPill } from '@/components/hrm/status-pill';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { fileSize } from '@/lib/employee-profile';
import {
    DOCUMENT_ACCEPT,
    documentProblem,
    documentTypeLabel,
    expiryState,
    MAX_DOCUMENTS,
    todayIso,
} from '@/lib/employee-files';
import { formatDate } from '@/lib/format-date';
import {
    destroy as destroyDocument,
    store as storeDocument,
} from '@/routes/employees/documents';

/** One document as the server sends it (never the stored path). */
export type EmployeeDocumentItem = {
    id: number;
    type: string;
    title: string;
    original_name: string;
    size_bytes: number;
    uploaded_at: string | null;
    expiry_date: string | null;
    download_url: string;
};

type Props = {
    employeeId: number;
    documents: EmployeeDocumentItem[];
    /** The document types the server accepts (config hrm.employees.document_types). */
    types: string[];
    /** Whether the viewer may add and delete documents (the edit-record rule). False: read-only list. */
    canManage: boolean;
    /** Called with true while an upload or deletion is in flight, so the page can hold its Save button. */
    onBusyChange?: (busy: boolean) => void;
};

/**
 * An employee's documents: type, title, file name, size, upload date and expiry (with an "Expired" or "Expires soon"
 * pill), each with Download and, when allowed, Delete. "Add document" opens a dialog that uploads by its own request.
 * Used by the edit form's Documents tab and the profile's Documents tab.
 */
export function DocumentsPanel({
    employeeId,
    documents,
    types,
    canManage,
    onBusyChange,
}: Props) {
    const { props } = usePage();
    const dateFormat = props.app.dateFormat;
    const today = todayIso();
    const [adding, setAdding] = useState(false);
    const [deleting, setDeleting] = useState<EmployeeDocumentItem | null>(null);
    const [removing, setRemoving] = useState(false);
    const [uploading, setUploading] = useState(false);
    const full = documents.length >= MAX_DOCUMENTS;
    const busy = removing || uploading;

    useEffect(() => {
        onBusyChange?.(busy);
    }, [busy, onBusyChange]);

    // Leaving the page mid-request must not leave the form's Save held.
    useEffect(() => () => onBusyChange?.(false), [onBusyChange]);

    const remove = () => {
        if (!deleting || removing) {
            return;
        }

        setRemoving(true);
        router.delete(
            destroyDocument.url({
                employee: employeeId,
                document: deleting.id,
            }),
            {
                // Asynchronous: the form's Save must not cancel this request.
                async: true,
                preserveScroll: true,
                preserveState: true,
                onFinish: () => {
                    setRemoving(false);
                    setDeleting(null);
                },
            },
        );
    };

    return (
        <div className="grid gap-4">
            {canManage && (
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <p className="text-sm text-muted-foreground">
                        {full
                            ? `This employee has ${MAX_DOCUMENTS} documents, the most allowed. Delete one to add another.`
                            : 'PDF, Word (DOC, DOCX) or PNG, JPG or WebP images, up to 10 MB each.'}
                    </p>
                    <Button
                        type="button"
                        size="sm"
                        disabled={full}
                        onClick={() => setAdding(true)}
                    >
                        <Plus />
                        Add document
                    </Button>
                </div>
            )}

            {documents.length === 0 ? (
                <p className="text-sm text-muted-foreground">
                    No documents yet.
                </p>
            ) : (
                <ul role="list" className="divide-y rounded-lg border">
                    {documents.map((item) => {
                        const state = expiryState(item.expiry_date, today);

                        return (
                            <li
                                key={item.id}
                                className="flex flex-wrap items-start gap-3 px-3 py-3 sm:flex-nowrap sm:items-center"
                            >
                                <FileText
                                    aria-hidden="true"
                                    className="mt-0.5 size-5 shrink-0 text-muted-foreground sm:mt-0"
                                />
                                <div className="min-w-0 flex-1 basis-48">
                                    <div className="flex flex-wrap items-center gap-x-2 gap-y-1">
                                        <span className="text-sm font-medium break-words">
                                            {item.title}
                                        </span>
                                        <span className="rounded-md bg-muted px-1.5 py-0.5 text-xs font-medium text-muted-foreground">
                                            {documentTypeLabel(item.type)}
                                        </span>
                                        {state === 'expired' && (
                                            <StatusPill tone="red">
                                                Expired
                                            </StatusPill>
                                        )}
                                        {state === 'soon' && (
                                            <StatusPill tone="amber">
                                                Expires soon
                                            </StatusPill>
                                        )}
                                    </div>
                                    <div className="mt-0.5 text-xs break-all text-muted-foreground">
                                        {item.original_name}
                                    </div>
                                    <div className="mt-0.5 text-xs text-muted-foreground">
                                        {fileSize(item.size_bytes)}
                                        {item.uploaded_at &&
                                            ` · Uploaded ${formatDate(item.uploaded_at, dateFormat)}`}
                                        {item.expiry_date &&
                                            ` · ${state === 'expired' ? 'Expired' : 'Expires'} ${formatDate(item.expiry_date, dateFormat)}`}
                                    </div>
                                </div>
                                <div className="flex w-full shrink-0 gap-2 pl-8 sm:w-auto sm:pl-0">
                                    <Button variant="outline" size="sm" asChild>
                                        <a
                                            href={item.download_url}
                                            download
                                            aria-label={`Download ${item.title}`}
                                        >
                                            <Download />
                                            Download
                                        </a>
                                    </Button>
                                    {canManage && (
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="sm"
                                            className="text-destructive"
                                            aria-label={`Delete ${item.title}`}
                                            onClick={() => setDeleting(item)}
                                        >
                                            <Trash2 />
                                            <span className="sm:sr-only">
                                                Delete
                                            </span>
                                        </Button>
                                    )}
                                </div>
                            </li>
                        );
                    })}
                </ul>
            )}

            {canManage && adding && (
                <AddDocumentDialog
                    employeeId={employeeId}
                    types={types}
                    onBusyChange={setUploading}
                    onClose={() => setAdding(false)}
                />
            )}

            <ConfirmDialog
                open={deleting !== null}
                onOpenChange={(open) => !open && setDeleting(null)}
                title="Delete document?"
                description={
                    deleting
                        ? `"${deleting.title}" and its file will be removed. This cannot be undone.`
                        : ''
                }
                processing={removing}
                onConfirm={remove}
            />
        </div>
    );
}

/** The "Add document" dialog. Mounted only while open, so it starts empty each time. */
function AddDocumentDialog({
    employeeId,
    types,
    onBusyChange,
    onClose,
}: {
    employeeId: number;
    types: string[];
    onBusyChange: (busy: boolean) => void;
    onClose: () => void;
}) {
    const form = useForm<{
        type: string;
        title: string;
        file: File | null;
        expiry_date: string;
    }>({
        type: types[0] ?? '',
        title: '',
        file: null,
        expiry_date: '',
    });

    useEffect(() => {
        onBusyChange(form.processing);
    }, [form.processing, onBusyChange]);

    useEffect(() => () => onBusyChange(false), [onBusyChange]);

    const aria = (field: 'type' | 'title' | 'file' | 'expiry_date') => ({
        'aria-invalid': form.errors[field] ? true : undefined,
        'aria-describedby': form.errors[field]
            ? `document-${field}-error`
            : undefined,
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        // The dialog renders in a portal but React events still bubble to the page's own <form>: keep it out.
        event.stopPropagation();

        // Never send the same upload twice.
        if (form.processing) {
            return;
        }

        if (form.data.file) {
            const problem = documentProblem(form.data.file);

            if (problem) {
                form.setError('file', problem);

                return;
            }
        }

        form.post(storeDocument.url(employeeId), {
            forceFormData: true,
            // Asynchronous: the form's Save must not cancel this upload.
            async: true,
            errorBag: 'document',
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => onClose(),
        });
    };

    return (
        <Dialog
            open
            onOpenChange={(open) => !open && !form.processing && onClose()}
        >
            <DialogContent>
                <form onSubmit={submit} className="grid gap-4" noValidate>
                    <DialogHeader>
                        <DialogTitle>Add document</DialogTitle>
                        <DialogDescription>
                            PDF, Word (DOC, DOCX) or a PNG, JPG or WebP image,
                            up to 10 MB.
                        </DialogDescription>
                    </DialogHeader>

                    <div className="grid gap-2">
                        <Label htmlFor="document-type">Type</Label>
                        <Select
                            value={form.data.type}
                            onValueChange={(value) =>
                                form.setData('type', value)
                            }
                        >
                            <SelectTrigger
                                id="document-type"
                                className="w-full"
                                {...aria('type')}
                            >
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {types.map((type) => (
                                    <SelectItem key={type} value={type}>
                                        {documentTypeLabel(type)}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <InputError
                            id="document-type-error"
                            role="alert"
                            message={form.errors.type}
                        />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="document-title">Title</Label>
                        <Input
                            id="document-title"
                            value={form.data.title}
                            maxLength={150}
                            autoComplete="off"
                            placeholder="e.g. Signed employment contract"
                            onChange={(e) =>
                                form.setData('title', e.target.value)
                            }
                            {...aria('title')}
                        />
                        <InputError
                            id="document-title-error"
                            role="alert"
                            message={form.errors.title}
                        />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="document-file">File</Label>
                        <Input
                            id="document-file"
                            type="file"
                            accept={DOCUMENT_ACCEPT}
                            onChange={(e) => {
                                form.clearErrors('file');
                                form.setData(
                                    'file',
                                    e.target.files?.[0] ?? null,
                                );
                            }}
                            {...aria('file')}
                        />
                        <InputError
                            id="document-file-error"
                            role="alert"
                            message={form.errors.file}
                        />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="document-expiry_date">
                            Expiry date (optional)
                        </Label>
                        <Input
                            id="document-expiry_date"
                            type="date"
                            min="2000-01-01"
                            value={form.data.expiry_date}
                            onChange={(e) =>
                                form.setData('expiry_date', e.target.value)
                            }
                            {...aria('expiry_date')}
                        />
                        <InputError
                            id="document-expiry_date-error"
                            role="alert"
                            message={form.errors.expiry_date}
                        />
                    </div>

                    <p
                        aria-live="polite"
                        className="text-xs text-muted-foreground empty:hidden"
                    >
                        {form.processing && form.progress
                            ? `Uploading… ${form.progress.percentage ?? 0}%`
                            : ''}
                    </p>

                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={onClose}
                            disabled={form.processing}
                        >
                            Cancel
                        </Button>
                        <Button type="submit" disabled={form.processing}>
                            {form.processing && <Spinner />}
                            Upload
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
