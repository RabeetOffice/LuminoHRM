import { Form, Head } from '@inertiajs/react';
import FirstLoginPasswordController from '@/actions/App/Http/Controllers/Auth/FirstLoginPasswordController';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { logout } from '@/routes';

type Props = {
    email: string;
    passwordRules: string;
};

/**
 * The first sign-in of an account whose password somebody else chose (the
 * first Admin's temporary one). Every other page leads here until it is done.
 */
export default function FirstLoginPassword({ email, passwordRules }: Props) {
    return (
        <>
            <Head title="Choose your own password" />

            <Form
                {...FirstLoginPasswordController.update.form()}
                resetOnError={[
                    'current_password',
                    'password',
                    'password_confirmation',
                ]}
            >
                {({ processing, errors }) => (
                    <div className="grid gap-6">
                        <div className="grid gap-2">
                            <Label htmlFor="email">Email</Label>
                            <Input
                                id="email"
                                type="email"
                                name="email"
                                autoComplete="username"
                                value={email}
                                readOnly
                            />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="current_password">
                                Temporary password
                            </Label>
                            <PasswordInput
                                id="current_password"
                                name="current_password"
                                autoComplete="current-password"
                                autoFocus
                                placeholder="The password you signed in with"
                            />
                            <InputError message={errors.current_password} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="password">New password</Label>
                            <PasswordInput
                                id="password"
                                name="password"
                                autoComplete="new-password"
                                placeholder="New password"
                                passwordrules={passwordRules}
                            />
                            <InputError message={errors.password} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="password_confirmation">
                                Confirm new password
                            </Label>
                            <PasswordInput
                                id="password_confirmation"
                                name="password_confirmation"
                                autoComplete="new-password"
                                placeholder="Confirm new password"
                                passwordrules={passwordRules}
                            />
                            <InputError
                                message={errors.password_confirmation}
                            />
                        </div>

                        <Button
                            type="submit"
                            className="w-full"
                            disabled={processing}
                            data-test="first-login-password-button"
                        >
                            {processing && <Spinner />}
                            Save my password
                        </Button>

                        <p className="text-center text-sm text-muted-foreground">
                            Not you?{' '}
                            <TextLink href={logout()} method="post" as="button">
                                Sign out
                            </TextLink>
                        </p>
                    </div>
                )}
            </Form>
        </>
    );
}

FirstLoginPassword.layout = {
    title: 'Welcome — choose your own password',
    description:
        'You signed in with a temporary password. Choose your own to continue; nobody else will know it.',
};
