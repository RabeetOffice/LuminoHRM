import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, Pencil, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { ChangeStatusButton } from '@/components/employees/change-status-button';
import { DeleteEmployeeDialog } from '@/components/employees/delete-employee-dialog';
import { EmployeeProfileView } from '@/components/employees/employee-profile-view';
import type { EmployeeProfile } from '@/components/employees/employee-profile-view';
import { PageHeader } from '@/components/hrm/page-header';
import { Button } from '@/components/ui/button';
import { edit, index, show } from '@/routes/employees';

type Props = {
    employee: EmployeeProfile;
    tabs: string[];
    statusOptions: string[];
    /** The types the "Add document" dialog offers. */
    documentTypes: string[];
    can: { edit: boolean; changeStatus: boolean; delete: boolean };
};

export default function EmployeeProfilePage({
    employee,
    tabs,
    statusOptions,
    documentTypes,
    can,
}: Props) {
    const [deleting, setDeleting] = useState(false);
    const { employment } = employee;

    return (
        <>
            <Head title={employee.name} />
            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <PageHeader
                    title={employee.name}
                    subtitle="View detailed information and activity for employee."
                    actions={
                        <>
                            <Button variant="outline" asChild>
                                <Link href={index()}>
                                    <ArrowLeft />
                                    Back
                                </Link>
                            </Button>
                            {can.changeStatus && employment && (
                                <ChangeStatusButton
                                    size="default"
                                    employee={{
                                        id: employee.id,
                                        name: employee.name,
                                        status: employment.status,
                                        last_working_day:
                                            employment.last_working_day,
                                        joining_date: employment.joining_date,
                                    }}
                                    statuses={statusOptions}
                                />
                            )}
                            {can.delete && (
                                <Button
                                    type="button"
                                    variant="outline"
                                    className="text-destructive"
                                    onClick={() => setDeleting(true)}
                                >
                                    <Trash2 />
                                    Delete
                                </Button>
                            )}
                            {can.edit && (
                                <Button asChild>
                                    <Link href={edit(employee.id)}>
                                        <Pencil />
                                        Edit
                                    </Link>
                                </Button>
                            )}
                        </>
                    }
                />

                {/* Documents are read-only unless the viewer may edit this record (same rule as the Edit button). */}
                <EmployeeProfileView
                    employee={employee}
                    tabs={tabs}
                    documentTypes={documentTypes}
                    canManageDocuments={can.edit}
                    idPrefix="employee-profile"
                />
            </div>

            <DeleteEmployeeDialog
                employee={
                    deleting ? { id: employee.id, name: employee.name } : null
                }
                onClose={() => setDeleting(false)}
            />
        </>
    );
}

EmployeeProfilePage.layout = (props: Props) => ({
    breadcrumbs: [
        { title: 'Employees', href: index() },
        { title: props.employee.name, href: show(props.employee.id) },
    ],
});
