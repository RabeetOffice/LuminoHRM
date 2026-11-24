import { Head } from '@inertiajs/react';
import { Info, UserRound } from 'lucide-react';
import { EmployeeProfileView } from '@/components/employees/employee-profile-view';
import type { EmployeeProfile } from '@/components/employees/employee-profile-view';
import { PageHeader } from '@/components/hrm/page-header';
import { profile } from '@/routes/my';

type Props = {
    /** The viewer's own record; null when the account is not linked to an employee. */
    employee: EmployeeProfile | null;
    tabs: string[];
    /** For people who manage employees: how an unlinked account gets linked. */
    showLinkHint: boolean;
};

/** The signed-in person's own record, read-only. Changes go through HR. */
export default function MyProfile({ employee, tabs, showLinkHint }: Props) {
    return (
        <>
            <Head title="My Profile" />
            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <PageHeader
                    title="My Profile"
                    subtitle={
                        employee
                            ? 'Your employee record as HR keeps it.'
                            : undefined
                    }
                />

                {employee ? (
                    <>
                        <p className="flex items-center gap-2 rounded-lg border bg-muted/40 px-4 py-3 text-sm text-muted-foreground">
                            <Info
                                aria-hidden="true"
                                className="size-4 shrink-0"
                            />
                            To change your details, contact HR.
                        </p>
                        <EmployeeProfileView
                            employee={employee}
                            tabs={tabs}
                            canManageDocuments={false}
                            idPrefix="my-profile"
                        />
                    </>
                ) : (
                    <section className="flex flex-col items-center rounded-xl border bg-card px-6 py-16 text-center shadow-xs">
                        <div className="flex size-12 items-center justify-center rounded-full bg-muted text-muted-foreground">
                            <UserRound aria-hidden="true" className="size-6" />
                        </div>
                        <p className="mt-4 text-base font-medium">
                            Your account is not linked to an employee record
                            yet.
                        </p>
                        {showLinkHint && (
                            <p className="mt-1 text-sm text-muted-foreground">
                                An Admin can link it by adding an employee with
                                your email.
                            </p>
                        )}
                    </section>
                )}
            </div>
        </>
    );
}

MyProfile.layout = {
    breadcrumbs: [{ title: 'My Profile', href: profile() }],
};
