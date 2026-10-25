import { Form, Head } from '@inertiajs/react';
import PasswordInput from '@/components/password-input';
import Field from '@/components/radio/field';
import { glassField } from '@/components/radio/glass-input';
import RadioKey from '@/components/radio/radio-key';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { update } from '@/routes/password';

type Props = {
    token: string;
    email: string;
    passwordRules: string;
};

export default function ResetPassword({ token, email, passwordRules }: Props) {
    return (
        <>
            <Head title="Reset password" />

            <Form
                {...update.form()}
                transform={(data) => ({ ...data, token, email })}
                resetOnSuccess={['password', 'password_confirmation']}
                className="grid gap-6"
            >
                {({ processing, errors }) => (
                    <>
                        <Field
                            label="Email"
                            htmlFor="email"
                            error={errors.email}
                        >
                            <Input
                                id="email"
                                type="email"
                                name="email"
                                autoComplete="email"
                                value={email}
                                className={`${glassField} opacity-80`}
                                readOnly
                            />
                        </Field>

                        <Field
                            label="Password"
                            htmlFor="password"
                            error={errors.password}
                        >
                            <PasswordInput
                                id="password"
                                name="password"
                                autoComplete="new-password"
                                autoFocus
                                placeholder="••••••••"
                                passwordrules={passwordRules}
                                className={`${glassField} pr-12`}
                                aria-invalid={!!errors.password}
                            />
                        </Field>

                        <Field
                            label="Confirm password"
                            htmlFor="password_confirmation"
                            error={errors.password_confirmation}
                        >
                            <PasswordInput
                                id="password_confirmation"
                                name="password_confirmation"
                                autoComplete="new-password"
                                placeholder="••••••••"
                                passwordrules={passwordRules}
                                className={`${glassField} pr-12`}
                                aria-invalid={!!errors.password_confirmation}
                            />
                        </Field>

                        <RadioKey
                            type="submit"
                            variant="walnut"
                            className="w-full"
                            disabled={processing}
                            data-test="reset-password-button"
                        >
                            {processing && <Spinner />}
                            Reset password
                        </RadioKey>
                    </>
                )}
            </Form>
        </>
    );
}

ResetPassword.layout = {
    title: 'Reset password',
    description: 'Please enter your new password below',
};
