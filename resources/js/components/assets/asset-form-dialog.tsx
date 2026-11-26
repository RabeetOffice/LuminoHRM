import { useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import AssetController from '@/actions/App/Http/Controllers/Assets/AssetController';
import { Field, fieldAria } from '@/components/settings/settings-form';
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
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { assetConditionOptions, costFieldText } from '@/lib/assets';

/** The asset as the asset page sends it (only the fields the form edits). */
export type AssetFormValues = {
    id: number;
    name: string;
    asset_category_id: number;
    serial_number: string | null;
    /** "Y-m-d" or null. */
    purchase_date: string | null;
    /** Paisa, or null. */
    cost: number | null;
    condition: string;
    notes: string | null;
};

type Props = {
    /** The asset being edited, or null to add a new one. */
    asset: AssetFormValues | null;
    categories: { id: number; name: string }[];
    onClose: () => void;
};

/**
 * Add / edit dialog (assets.store / assets.update). Mount it only while open, with a key, so the form starts fresh.
 * The cost is typed in whole rupees. The server's refusals (a taken serial number, a date in the future) show under
 * the field they belong to.
 */
export function AssetFormDialog({ asset, categories, onClose }: Props) {
    const form = useForm({
        name: asset?.name ?? '',
        asset_category_id: asset ? String(asset.asset_category_id) : '',
        serial_number: asset?.serial_number ?? '',
        purchase_date: asset?.purchase_date ?? '',
        cost_rupees: costFieldText(asset?.cost),
        condition: asset?.condition ?? 'new',
        notes: asset?.notes ?? '',
    });
    const { data, errors } = form;

    const submit = (event: FormEvent) => {
        event.preventDefault();

        const options = { preserveScroll: true, onSuccess: () => onClose() };

        if (asset) {
            form.submit(AssetController.update(asset.id), options);
        } else {
            form.submit(AssetController.store(), options);
        }
    };

    return (
        <Dialog
            open
            onOpenChange={(open) => !open && !form.processing && onClose()}
        >
            <DialogContent className="max-h-[90dvh] overflow-y-auto sm:max-w-lg">
                <form onSubmit={submit} className="grid gap-5" noValidate>
                    <DialogHeader>
                        <DialogTitle>
                            {asset ? 'Edit asset' : 'Add asset'}
                        </DialogTitle>
                        <DialogDescription>
                            {asset
                                ? 'Change what is recorded about this asset. Who has it and its status are changed from the buttons on the asset page.'
                                : 'The asset code is made for you. A new asset starts as available.'}
                        </DialogDescription>
                    </DialogHeader>

                    <Field id="asset-name" label="Name" error={errors.name}>
                        <Input
                            {...fieldAria('asset-name', errors.name)}
                            value={data.name}
                            onChange={(e) =>
                                form.setData('name', e.target.value)
                            }
                            maxLength={120}
                            autoComplete="off"
                            placeholder="For example: Dell Latitude 5440"
                        />
                    </Field>

                    <div className="grid gap-5 sm:grid-cols-2">
                        <Field
                            id="asset-category"
                            label="Category"
                            error={errors.asset_category_id}
                        >
                            <Select
                                value={data.asset_category_id}
                                onValueChange={(value) =>
                                    form.setData('asset_category_id', value)
                                }
                            >
                                <SelectTrigger
                                    {...fieldAria(
                                        'asset-category',
                                        errors.asset_category_id,
                                    )}
                                    className="w-full"
                                >
                                    <SelectValue placeholder="Choose a category" />
                                </SelectTrigger>
                                <SelectContent>
                                    {categories.map((category) => (
                                        <SelectItem
                                            key={category.id}
                                            value={String(category.id)}
                                        >
                                            {category.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </Field>
                        <Field
                            id="asset-condition"
                            label="Condition"
                            error={errors.condition}
                        >
                            <Select
                                value={data.condition}
                                onValueChange={(value) =>
                                    form.setData('condition', value)
                                }
                            >
                                <SelectTrigger
                                    {...fieldAria(
                                        'asset-condition',
                                        errors.condition,
                                    )}
                                    className="w-full"
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {assetConditionOptions().map((option) => (
                                        <SelectItem
                                            key={option.value}
                                            value={option.value}
                                        >
                                            {option.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </Field>
                        <Field
                            id="asset-serial"
                            label="Serial number"
                            error={errors.serial_number}
                            hint="Optional. Two assets cannot share one."
                        >
                            <Input
                                {...fieldAria(
                                    'asset-serial',
                                    errors.serial_number,
                                    true,
                                )}
                                value={data.serial_number}
                                onChange={(e) =>
                                    form.setData(
                                        'serial_number',
                                        e.target.value,
                                    )
                                }
                                maxLength={100}
                                autoComplete="off"
                            />
                        </Field>
                        <Field
                            id="asset-purchase"
                            label="Purchase date"
                            error={errors.purchase_date}
                            hint="Optional."
                        >
                            <Input
                                {...fieldAria(
                                    'asset-purchase',
                                    errors.purchase_date,
                                    true,
                                )}
                                type="date"
                                value={data.purchase_date}
                                onChange={(e) =>
                                    form.setData(
                                        'purchase_date',
                                        e.target.value,
                                    )
                                }
                            />
                        </Field>
                    </div>

                    <Field
                        id="asset-cost"
                        label="Cost"
                        error={errors.cost_rupees}
                        hint="Optional. Whole rupees."
                    >
                        <div className="flex items-center gap-2">
                            <span className="text-sm text-muted-foreground">
                                Rs
                            </span>
                            <Input
                                {...fieldAria(
                                    'asset-cost',
                                    errors.cost_rupees,
                                    true,
                                )}
                                inputMode="numeric"
                                className="w-40"
                                value={data.cost_rupees}
                                onChange={(e) =>
                                    form.setData('cost_rupees', e.target.value)
                                }
                                autoComplete="off"
                            />
                        </div>
                    </Field>

                    <Field id="asset-notes" label="Notes" error={errors.notes}>
                        <Textarea
                            {...fieldAria('asset-notes', errors.notes)}
                            value={data.notes}
                            onChange={(e) =>
                                form.setData('notes', e.target.value)
                            }
                            maxLength={1000}
                            rows={3}
                        />
                    </Field>

                    <DialogFooter className="flex-col-reverse gap-2 sm:flex-row">
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
                            {asset ? 'Save changes' : 'Add asset'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
