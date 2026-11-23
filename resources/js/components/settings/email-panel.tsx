import { router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import HrmSettingsController from '@/actions/App/Http/Controllers/Settings/HrmSettingsController';
import PasswordInput from '@/components/password-input';
import { CheckField } from '@/components/settings/fields';
import {
    Field,
    fieldAria,
    type Editable,
    SettingsCard,
    useSettingsSubmit,
} from '@/components/settings/settings-form';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import type { EmailValues } from '@/types/hrm-settings';

type EmailForm = Editable<Omit<EmailValues, 'has_password'>> & {
    password: string;
    clear_password: boolean;
};

const ENCRYPTION = [
    { value: 'tls', label: 'STARTTLS (usually port 587)' },
    { value: 'ssl', label: 'SSL/TLS (usually port 465)' },
    { value: 'none', label: 'None (not recommended)' },
];

export function EmailPanel({ values }: { values: EmailValues }) {
    const { has_password: hasPassword, ...saved } = values;
    const form = useForm<EmailForm>({
        ...saved,
        password: '',
        clear_password: false,
    });
    const { submit, state } = useSettingsSubmit('email', form, {
        // The typed password must not stay in the browser once it is saved.
        afterSave: { password: '', clear_password: false },
    });
    const { data, errors, setData } = form;
    const [testing, setTesting] = useState(false);

    const sendTest = () => {
        router.post(
            HrmSettingsController.testEmail().url,
            {},
            {
                preserveScroll: true,
                onStart: () => setTesting(true),
                onFinish: () => setTesting(false),
            },
        );
    };

    return (
        <div className="grid gap-6">
            <form onSubmit={submit} className="grid gap-6" noValidate>
                <SettingsCard
                    title="SMTP server"
                    description="Leave the host empty to use the mail setup of the server instead."
                    state={state}
                >
                    <div className="grid gap-5 sm:grid-cols-[1fr_8rem]">
                        <Field id="mail-host" label="Host" error={errors.host}>
                            <Input
                                {...fieldAria('mail-host', errors.host)}
                                value={data.host ?? ''}
                                placeholder="smtp.example.com"
                                autoComplete="off"
                                spellCheck={false}
                                onChange={(e) =>
                                    setData('host', e.target.value)
                                }
                            />
                        </Field>
                        <Field id="mail-port" label="Port" error={errors.port}>
                            <Input
                                {...fieldAria('mail-port', errors.port)}
                                type="number"
                                inputMode="numeric"
                                min={1}
                                max={65535}
                                value={data.port ?? ''}
                                onChange={(e) =>
                                    setData('port', e.target.value)
                                }
                            />
                        </Field>
                    </div>
                    <Field
                        id="mail-encryption"
                        label="Encryption"
                        error={errors.encryption}
                    >
                        <Select
                            value={data.encryption}
                            onValueChange={(v) => setData('encryption', v)}
                        >
                            <SelectTrigger
                                {...fieldAria(
                                    'mail-encryption',
                                    errors.encryption,
                                )}
                                className="w-full"
                            >
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {ENCRYPTION.map((o) => (
                                    <SelectItem key={o.value} value={o.value}>
                                        {o.label}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </Field>
                    <div className="grid gap-5 sm:grid-cols-2">
                        <Field
                            id="mail-username"
                            label="Username"
                            error={errors.username}
                        >
                            <Input
                                {...fieldAria('mail-username', errors.username)}
                                value={data.username ?? ''}
                                autoComplete="off"
                                onChange={(e) =>
                                    setData('username', e.target.value)
                                }
                            />
                        </Field>
                        <Field
                            id="mail-password"
                            label="Password"
                            hint={
                                hasPassword
                                    ? 'A password is saved. Leave this blank to keep it.'
                                    : undefined
                            }
                            error={errors.password}
                        >
                            <PasswordInput
                                {...fieldAria(
                                    'mail-password',
                                    errors.password,
                                    hasPassword,
                                )}
                                value={data.password}
                                autoComplete="new-password"
                                onChange={(e) =>
                                    setData('password', e.target.value)
                                }
                            />
                        </Field>
                    </div>
                    {hasPassword && (
                        <CheckField
                            id="mail-clear-password"
                            label="Remove the saved password"
                            checked={data.clear_password}
                            onChange={(v) => setData('clear_password', v)}
                        />
                    )}
                </SettingsCard>

                <SettingsCard
                    title="Sender"
                    description="The name and address people see on email from the HRM."
                    state={state}
                >
                    <div className="grid gap-5 sm:grid-cols-2">
                        <Field
                            id="mail-from-name"
                            label="From name"
                            error={errors.from_name}
                        >
                            <Input
                                {...fieldAria(
                                    'mail-from-name',
                                    errors.from_name,
                                )}
                                value={data.from_name ?? ''}
                                onChange={(e) =>
                                    setData('from_name', e.target.value)
                                }
                            />
                        </Field>
                        <Field
                            id="mail-from-address"
                            label="From address"
                            error={errors.from_address}
                        >
                            <Input
                                {...fieldAria(
                                    'mail-from-address',
                                    errors.from_address,
                                )}
                                type="email"
                                value={data.from_address ?? ''}
                                onChange={(e) =>
                                    setData('from_address', e.target.value)
                                }
                            />
                        </Field>
                    </div>
                </SettingsCard>
            </form>

            <SettingsCard
                title="Send a test email"
                description="Sends a short message to your own sign-in address using the saved settings. Save first if you changed anything above."
                state={state}
                footer={
                    <Button
                        type="button"
                        variant="outline"
                        onClick={sendTest}
                        disabled={testing || state.dirty}
                    >
                        {testing && <Spinner />}
                        Send test email
                    </Button>
                }
            >
                <p className="text-sm text-muted-foreground">
                    {state.dirty
                        ? 'You have unsaved changes above. Save them before testing.'
                        : 'The result appears as a message in the corner of the screen.'}
                </p>
            </SettingsCard>
        </div>
    );
}
