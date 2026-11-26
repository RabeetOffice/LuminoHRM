import { Head, router, useForm } from '@inertiajs/react';
import { Pencil, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import { toast } from 'sonner';
import AssetCategoryController from '@/actions/App/Http/Controllers/Settings/AssetCategoryController';
import { ConfirmDialog } from '@/components/hrm/confirm-dialog';
import { DataTable } from '@/components/hrm/data-table';
import type { DataTableColumn } from '@/components/hrm/data-table';
import { PageHeader } from '@/components/hrm/page-header';
import { Field, fieldAria } from '@/components/settings/settings-form';
import {
    ASSET_CATEGORIES_ENTRY,
    SettingsNav,
} from '@/components/settings/settings-nav';
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
import { Spinner } from '@/components/ui/spinner';
import { index as settingsIndex } from '@/routes/hrm-settings';
import { index } from '@/routes/hrm-settings/asset-categories';
import type { SettingsGroup } from '@/types/hrm-settings';

type CategoryRow = {
    id: number;
    name: string;
    assets_count: number;
    can_delete: boolean;
};

type Props = {
    groups: SettingsGroup[];
    categories: CategoryRow[];
};

const assetsText = (count: number) =>
    count === 1 ? '1 asset' : `${count} assets`;

export default function AssetCategories({ groups, categories }: Props) {
    // A row being renamed, 'new' for the add dialog, or null.
    const [editing, setEditing] = useState<CategoryRow | 'new' | null>(null);
    // The row asked about stays set while the dialog animates closed, so its text does not change mid-close.
    const [pending, setPending] = useState<CategoryRow | null>(null);
    const [confirmOpen, setConfirmOpen] = useState(false);
    const [busy, setBusy] = useState(false);

    const confirmDelete = () => {
        if (!pending) {
            return;
        }

        router.delete(AssetCategoryController.destroy(pending.id).url, {
            preserveScroll: true,
            onStart: () => setBusy(true),
            // A late refusal (an asset was added since this page loaded) comes back as an error on `category`.
            onError: (errors) => {
                toast.error(
                    errors.category ?? 'The category could not be deleted.',
                );
            },
            onFinish: () => {
                setBusy(false);
                setConfirmOpen(false);
            },
        });
    };

    const columns: DataTableColumn<CategoryRow>[] = [
        {
            key: 'name',
            header: 'Category',
            className: 'font-medium',
            cell: (row) => row.name,
        },
        {
            key: 'assets',
            header: 'Assets',
            className: 'text-muted-foreground tabular-nums',
            cell: (row) => assetsText(row.assets_count),
        },
        {
            key: 'actions',
            header: <span className="sr-only">Actions</span>,
            className: 'w-24',
            cell: (row) => (
                <div className="flex justify-end gap-1">
                    <Button
                        variant="ghost"
                        size="icon"
                        className="size-8"
                        aria-label={`Rename ${row.name}`}
                        title="Rename"
                        onClick={() => setEditing(row)}
                    >
                        <Pencil />
                    </Button>
                    <Button
                        variant="ghost"
                        size="icon"
                        className="size-8 text-destructive hover:text-destructive"
                        aria-label={`Delete ${row.name}`}
                        title={
                            row.can_delete
                                ? 'Delete'
                                : `${assetsText(row.assets_count)} use this category. Move them to another category first.`
                        }
                        disabled={!row.can_delete}
                        onClick={() => {
                            setPending(row);
                            setConfirmOpen(true);
                        }}
                    >
                        <Trash2 />
                    </Button>
                </div>
            ),
        },
    ];

    return (
        <>
            <Head title="Asset categories" />
            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <PageHeader
                    title="Settings"
                    subtitle="Company-wide rules. Each section saves on its own."
                />

                <div className="grid items-start gap-6 lg:grid-cols-[16rem_minmax(0,1fr)]">
                    <SettingsNav groups={groups} active="asset-categories" />

                    <div className="grid min-w-0 gap-6">
                        <div className="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <h2 className="text-lg font-semibold tracking-tight">
                                    {ASSET_CATEGORIES_ENTRY.label}
                                </h2>
                                <p className="text-sm text-muted-foreground">
                                    {ASSET_CATEGORIES_ENTRY.description} A
                                    category that assets use cannot be deleted.
                                </p>
                            </div>
                            <Button onClick={() => setEditing('new')}>
                                <Plus />
                                Add category
                            </Button>
                        </div>

                        <DataTable
                            label="Asset categories"
                            columns={columns}
                            rows={categories}
                            rowKey={(row) => row.id}
                            emptyText="No categories yet."
                        />
                    </div>
                </div>
            </div>

            {editing !== null && (
                <CategoryDialog
                    key={editing === 'new' ? 'new' : editing.id}
                    category={editing === 'new' ? null : editing}
                    onClose={() => setEditing(null)}
                />
            )}

            <ConfirmDialog
                open={confirmOpen}
                onOpenChange={setConfirmOpen}
                title="Delete category?"
                description={
                    pending
                        ? `"${pending.name}" will be deleted. This cannot be undone.`
                        : ''
                }
                processing={busy}
                onConfirm={confirmDelete}
            />
        </>
    );
}

/** Add / rename dialog. Mounted only while open (with a key) so the form starts fresh. */
function CategoryDialog({
    category,
    onClose,
}: {
    category: CategoryRow | null;
    onClose: () => void;
}) {
    const form = useForm({ name: category?.name ?? '' });
    const { data, errors } = form;

    const submit = (event: FormEvent) => {
        event.preventDefault();

        const options = { preserveScroll: true, onSuccess: () => onClose() };

        if (category) {
            form.submit(AssetCategoryController.update(category.id), options);
        } else {
            form.submit(AssetCategoryController.store(), options);
        }
    };

    return (
        <Dialog
            open
            onOpenChange={(open) => !open && !form.processing && onClose()}
        >
            <DialogContent className="sm:max-w-md">
                <form onSubmit={submit} className="grid gap-5" noValidate>
                    <DialogHeader>
                        <DialogTitle>
                            {category ? 'Rename category' : 'Add category'}
                        </DialogTitle>
                        <DialogDescription>
                            {category
                                ? 'The assets in this category keep it under the new name.'
                                : 'A new category appears at the end of the list.'}
                        </DialogDescription>
                    </DialogHeader>

                    <Field id="category-name" label="Name" error={errors.name}>
                        <Input
                            {...fieldAria('category-name', errors.name)}
                            value={data.name}
                            onChange={(e) =>
                                form.setData('name', e.target.value)
                            }
                            maxLength={60}
                            autoComplete="off"
                            autoFocus
                        />
                    </Field>

                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            disabled={form.processing}
                            onClick={onClose}
                        >
                            Cancel
                        </Button>
                        <Button type="submit" disabled={form.processing}>
                            {form.processing && <Spinner />}
                            {category ? 'Save' : 'Add category'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

AssetCategories.layout = {
    breadcrumbs: [
        { title: 'Settings', href: settingsIndex() },
        { title: 'Asset categories', href: index() },
    ],
};
