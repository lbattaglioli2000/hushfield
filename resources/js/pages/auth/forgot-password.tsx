import { Form, Head } from '@inertiajs/react';
import Field from '@/components/radio/field';
import { glassField } from '@/components/radio/glass-input';
import RadioKey from '@/components/radio/radio-key';
import SignalCard from '@/components/radio/signal-card';
import TextLink from '@/components/text-link';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { login } from '@/routes';
import { email } from '@/routes/password';

export default function ForgotPassword({ status }: { status?: string }) {
    return (
        <>
            <Head title="Forgot password" />

            {status && (
                <SignalCard tone="amber" className="mb-6">
                    {status}
                </SignalCard>
            )}

            <Form {...email.form()} className="grid gap-6">
                {({ processing, errors }) => (
                    <>
                        <Field
                            label="Email address"
                            htmlFor="email"
                            error={errors.email}
                        >
                            <Input
                                id="email"
                                type="email"
                                name="email"
                                autoComplete="off"
                                autoFocus
                                placeholder="wren@hushfield.town"
                                className={glassField}
                                aria-invalid={!!errors.email}
                            />
                        </Field>

                        <RadioKey
                            type="submit"
                            variant="walnut"
                            className="w-full"
                            disabled={processing}
                            data-test="email-password-reset-link-button"
                        >
                            {processing && <Spinner />}
                            Email password reset link
                        </RadioKey>
                    </>
                )}
            </Form>

            <p className="mt-6 text-center text-sm text-ink/70">
                Or, return to <TextLink href={login()}>log in</TextLink>
            </p>
        </>
    );
}

ForgotPassword.layout = {
    title: 'Forgot password',
    description: 'Enter your email to receive a password reset link',
};
