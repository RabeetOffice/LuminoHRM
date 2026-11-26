import { useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import PayrollRunController from '@/actions/App/Http/Controllers/Payroll/PayrollRunController';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Spinner } from '@/components/ui/spinner';
import { formatMoney } from '@/lib/payroll';

type Props = {
    runId: number;
    label: string;
    headcount: number;
    gross: number;
    tax: number;
    net: number;
    /** How many payslips are generated (included lines). */
    payslipCount: number;
    /** Of those, how many go by email (active login with an email). */
    emailedCount: number;
    /** The approver's own line is in this run (allowed only for the sole active Admin). */
    approvesOwn: boolean;
    /** The fingerprint of the figures the page was rendered with. */
    fingerprint: string;
    onClose: () => void;
};

/**
 * Approving freezes the month: figures, payslips and the bank sheet are final,
 * loan installments are taken off and payslips are emailed. The fingerprint
 * of the figures this page shows goes with the request, so figures that
 * changed in the meantime are caught instead of approved unseen. Mount it only
 * while open.
 */
export function ApproveRunDialog({
    runId,
    label,
    headcount,
    gross,
    tax,
    net,
    payslipCount,
    emailedCount,
    approvesOwn,
    fingerprint,
    onClose,
}: Props) {
    const form = useForm({ fingerprint });
    // The refusal of the rules (not tied to one field) comes back under `run`.
    const bag = form.errors as Record<string, string | undefined>;
    const runError = bag.run ?? bag.month;

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.submit(PayrollRunController.approve(runId), {
            preserveScroll: true,
            // Approved, or "figures changed": either way the page has fresh props.
            onSuccess: () => onClose(),
        });
    };

    const rows: [string, string][] = [
        ['People', String(headcount)],
        ['Gross pay', formatMoney(gross)],
        ['Tax', formatMoney(tax)],
        ['Net pay', formatMoney(net)],
    ];

    return (
        <Dialog
            open
            onOpenChange={(open) => !open && !form.processing && onClose()}
        >
            <DialogContent className="sm:max-w-md">
                <form onSubmit={submit} className="grid gap-5" noValidate>
                    <DialogHeader>
                        <DialogTitle>Approve payroll for {label}?</DialogTitle>
                        <DialogDescription>
                            The figures become final and the month is locked:
                            attendance, leave and salaries for {label} can no
                            longer change. This cannot be undone.
                        </DialogDescription>
                    </DialogHeader>
                    <dl className="grid gap-1.5 rounded-lg border bg-muted/30 p-3 text-sm">
                        {rows.map(([name, value]) => (
                            <div
                                key={name}
                                className="flex justify-between gap-3"
                            >
                                <dt className="text-muted-foreground">
                                    {name}
                                </dt>
                                <dd className="font-medium tabular-nums">
                                    {value}
                                </dd>
                            </div>
                        ))}
                    </dl>
                    <p className="text-sm text-muted-foreground">
                        {payslipCount}{' '}
                        {payslipCount === 1 ? 'payslip' : 'payslips'} will be
                        generated; {emailedCount} will be emailed.
                    </p>
                    {approvesOwn && (
                        <p className="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200">
                            This payroll includes your own pay. You are the only
                            active Admin, so you may approve it; it is recorded
                            in the audit log.
                        </p>
                    )}
                    {runError && (
                        <p role="alert" className="text-sm text-destructive">
                            {runError}
                        </p>
                    )}
                    {form.errors.fingerprint && (
                        <p role="alert" className="text-sm text-destructive">
                            {form.errors.fingerprint}
                        </p>
                    )}
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
                            Approve payroll
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
