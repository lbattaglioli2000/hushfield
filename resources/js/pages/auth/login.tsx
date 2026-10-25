import { Form, Head, setLayoutProps } from '@inertiajs/react';
import LauncherSubmit from '@/components/launcher-submit';
import PasskeyVerify from '@/components/passkey-verify';
import PasswordInput from '@/components/password-input';
import Field from '@/components/radio/field';
import { glassField } from '@/components/radio/glass-input';
import SignalCard from '@/components/radio/signal-card';
import TextLink from '@/components/text-link';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { register } from '@/routes';
import { store } from '@/routes/login';
import { request } from '@/routes/password';

type Props = {
    status?: string;
    canResetPassword: boolean;
    copy: {
        launcher_login_title: string;
        launcher_login_intro: string;
        launcher_login_hint: string;
    };
};

export default function Login({ status, canResetPassword, copy }: Props) {
    setLayoutProps({
        title: copy.launcher_login_title,
        description: copy.launcher_login_intro,
    });

    return (
        <>
            <Head title="Log in" />

            {status && (
                <SignalCard tone="amber" className="mb-6">
                    {status}
                </SignalCard>
            )}

            <PasskeyVerify />

            <Form
                {...store.form()}
                resetOnSuccess={['password']}
                className="grid gap-6"
            >
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
                                required
                                autoFocus
                                tabIndex={1}
                                autoComplete="email"
                                placeholder="wren@hushfield.town"
                                className={glassField}
                                aria-invalid={!!errors.email}
                            />
                        </Field>

                        <Field
                            label="Password"
                            htmlFor="password"
                            error={errors.password}
                            aside={
                                canResetPassword && (
                                    <TextLink
                                        href={request()}
                                        className="text-xs"
                                        tabIndex={5}
                                    >
                                        Forgot your password?
                                    </TextLink>
                                )
                            }
                        >
                            <PasswordInput
                                id="password"
                                name="password"
                                required
                                tabIndex={2}
                                autoComplete="current-password"
                                placeholder="••••••••"
                                className={`${glassField} pr-12`}
                                aria-invalid={!!errors.password}
                            />
                        </Field>

                        <div className="flex items-center gap-3">
                            <Checkbox
                                id="remember"
                                name="remember"
                                tabIndex={3}
                            />
                            <label
                                htmlFor="remember"
                                className="text-xs font-semibold tracking-[0.2em] text-ink uppercase"
                            >
                                Remember me
                            </label>
                        </div>

                        <LauncherSubmit
                            processing={processing}
                            label="Log in"
                            hint={copy.launcher_login_hint}
                            tabIndex={4}
                            data-test="login-button"
                        />

                        <p className="text-center text-sm text-ink/70">
                            Don't have an account?{' '}
                            <TextLink href={register()} tabIndex={5}>
                                Sign up
                            </TextLink>
                        </p>
                    </>
                )}
            </Form>
        </>
    );
}
