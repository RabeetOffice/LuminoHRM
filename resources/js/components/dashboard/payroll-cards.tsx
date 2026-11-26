import { Download, ReceiptText } from 'lucide-react';
import type { ReactNode } from 'react';
import { Button } from '@/components/ui/button';
import { formatRupees } from '@/lib/dashboard';
import type { LatestPayslip } from '@/lib/dashboard';

/** The dashboard card frame (the same look as the page's other cards). */
function PayrollCardShell({
    title,
    children,
}: {
    title: string;
    children: ReactNode;
}) {
    return (
        <div className="flex min-h-64 flex-col rounded-xl border bg-card shadow-xs">
            <div className="flex items-center justify-between gap-3 border-b px-5 py-4">
                <h3 className="text-base font-semibold">{title}</h3>
            </div>
            {children}
        </div>
    );
}

/**
 * "My latest payslip": the viewer's own latest payslip of an approved or paid month (never a draft), with the PDF.
 * Without one, a short note (only shown to people who do not see payroll).
 */
export function LatestPayslipCard({
    payslip,
}: {
    payslip: LatestPayslip | null;
}) {
    if (!payslip) {
        return (
            <PayrollCardShell title="My latest payslip">
                <div className="flex flex-1 items-center justify-center p-6 text-center text-sm text-muted-foreground">
                    Your payslip appears here once a payroll month is approved.
                </div>
            </PayrollCardShell>
        );
    }

    return (
        <PayrollCardShell title="My latest payslip">
            <div className="flex flex-1 flex-col justify-between gap-6 p-5">
                <div className="flex items-start gap-3">
                    <div className="flex size-10 shrink-0 items-center justify-center rounded-lg bg-teal-100 text-teal-700 dark:bg-teal-500/20 dark:text-teal-300">
                        <ReceiptText className="size-5" aria-hidden="true" />
                    </div>
                    <div className="min-w-0">
                        <p className="text-sm text-muted-foreground">
                            {payslip.label}
                        </p>
                        <p className="mt-1 text-2xl font-semibold tabular-nums">
                            {formatRupees(payslip.net)}
                        </p>
                        <p className="mt-1 text-xs text-muted-foreground">
                            Net pay
                        </p>
                    </div>
                </div>
                {payslip.download_url && (
                    <Button asChild variant="outline" className="self-start">
                        {/* A file download: a plain link, not an Inertia visit. */}
                        <a href={payslip.download_url}>
                            <Download />
                            Download payslip
                        </a>
                    </Button>
                )}
            </div>
        </PayrollCardShell>
    );
}
