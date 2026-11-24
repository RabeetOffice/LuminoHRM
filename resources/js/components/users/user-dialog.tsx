import { useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import UserController from '@/actions/App/Http/Controllers/UserController';
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

export type UserRow = {
    id: number;
    name: string;
    email: string;
    role: string | null;
    is_active: boolean;
    last_login_at: string | null;
    is_self: boolean;
};

export type RoleOption = { value: string; label: string };

type Props = {
    /** The account being edited, or null to create one. */
    user: UserRow | null;
    roles: RoleOption[];
    onClose: () => void;
};

/** Create / edit dialog. Mount it only while open (with a key) so the form starts fresh. */
export function UserDialog({ user, roles, onClose }: Props) {
    const form = useForm({
        name: user?.name ?? '',
        email: user?.email ?? '',
        role: user?.role ?? '',
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();

        // A row without a role must not submit an empty one: make the admin choose.
        if (form.data.role === '') {
            form.setError('role', 'Choose a role.');

            return;
        }

        form.clearErrors('role');

        const options = {
            preserveScroll: true,
            onSuccess: () => onClose(),
        };

        if (user) {
            form.submit(UserController.update(user.id), options);
        } else {
            form.submit(UserController.store(), options);
        }
    };

    // Your own role is fixed here so you cannot lock yourself out.
    const roleLocked = user?.is_self ?? false;

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent>
                <form onSubmit={submit} className="grid gap-4" noValidate>
                    <DialogHeader>
                        <DialogTitle>
                            {user ? 'Edit user' : 'Add user'}
                        </DialogTitle>
                        <DialogDescription>
                            {user
                                ? 'Change the name, email or role of this account.'
                                : 'We email the new user a link to set their own password. You never see it.'}
                        </DialogDescription>
                    </DialogHeader>

                    <div className="grid gap-2">
                        <Label htmlFor="user-name">Name</Label>
                        <Input
                            id="user-name"
                            value={form.data.name}
                            onChange={(e) =>
                                form.setData('name', e.target.value)
                            }
                            maxLength={255}
                            autoFocus
                            autoComplete="off"
                            aria-invalid={form.errors.name ? true : undefined}
                            aria-describedby={
                                form.errors.name ? 'user-name-error' : undefined
                            }
                        />
                        <InputError
                            id="user-name-error"
                            role="alert"
                            message={form.errors.name}
                        />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="user-email">Email</Label>
                        <Input
                            id="user-email"
                            type="email"
                            value={form.data.email}
                            onChange={(e) =>
                                form.setData('email', e.target.value)
                            }
                            maxLength={255}
                            autoComplete="off"
                            aria-invalid={form.errors.email ? true : undefined}
                            aria-describedby={
                                form.errors.email
                                    ? 'user-email-error'
                                    : undefined
                            }
                        />
                        <InputError
                            id="user-email-error"
                            role="alert"
                            message={form.errors.email}
                        />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="user-role">Role</Label>
                        <Select
                            value={form.data.role}
                            onValueChange={(value) =>
                                form.setData('role', value)
                            }
                            disabled={roleLocked}
                        >
                            <SelectTrigger
                                id="user-role"
                                className="w-full"
                                aria-invalid={
                                    form.errors.role ? true : undefined
                                }
                                aria-describedby={
                                    roleLocked
                                        ? 'user-role-hint'
                                        : form.errors.role
                                          ? 'user-role-error'
                                          : undefined
                                }
                            >
                                <SelectValue placeholder="Select a role" />
                            </SelectTrigger>
                            <SelectContent>
                                {roles.map((role) => (
                                    <SelectItem
                                        key={role.value}
                                        value={role.value}
                                    >
                                        {role.label}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        {roleLocked && (
                            <p
                                id="user-role-hint"
                                className="text-xs text-muted-foreground"
                            >
                                You cannot change your own role.
                            </p>
                        )}
                        <InputError
                            id="user-role-error"
                            role="alert"
                            message={form.errors.role}
                        />
                    </div>

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
                            {user ? 'Save changes' : 'Add user'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
