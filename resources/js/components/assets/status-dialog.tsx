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
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { assetStatusLabel, statusChoices } from '@/lib/assets';

type Props = {
    asset: { id: number; asset_code: string; status: string };
    /** The statuses the server says this viewer may choose (never `assigned`). */
    options: string[];
    onClose: () => void;
};

/**
 * Change an asset's status by hand (assets.status): in repair, back to available, or retired (Admin). Mount it only
 * while open. Refusals (the asset was just handed out, a retired asset cannot go to repair) show under the field.
 */
export function StatusDialog({ asset, options, onClose }: Props) {
    const form = useForm({ status: options[0] ?? '' });
    const { data, errors } = form;

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.submit(AssetController.status(asset.id), {
            preserveScroll: true,
            onSuccess: () => onClose(),
        });
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
                            Change status of {asset.asset_code}
                        </DialogTitle>
                        <DialogDescription>
                            It is {assetStatusLabel(asset.status).toLowerCase()}{' '}
                            now. An asset is marked assigned only by handing it
                            to someone, and returns to available when it comes
                            back.
                        </DialogDescription>
                    </DialogHeader>

                    <Field
                        id="status-new"
                        label="New status"
                        error={errors.status}
                    >
                        <Select
                            value={data.status}
                            onValueChange={(value) =>
                                form.setData('status', value)
                            }
                        >
                            <SelectTrigger
                                {...fieldAria('status-new', errors.status)}
                                className="w-full"
                            >
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {statusChoices(options).map((option) => (
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

                    <DialogFooter className="flex-col-reverse gap-2 sm:flex-row">
                        <Button
                            type="button"
                            variant="outline"
                            onClick={onClose}
                            disabled={form.processing}
                        >
                            Cancel
                        </Button>
                        <Button
                            type="submit"
                            disabled={form.processing || data.status === ''}
                        >
                            {form.processing && <Spinner />}
                            Change status
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
