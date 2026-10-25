import { Form, Head } from '@inertiajs/react';
import InputError from '@/components/input-error';
import LauncherSubmit from '@/components/launcher-submit';
import PasswordInput from '@/components/password-input';
import Field from '@/components/radio/field';
import { glassField } from '@/components/radio/glass-input';
import RadioKey from '@/components/radio/radio-key';
import SignalCard from '@/components/radio/signal-card';
import TextLink from '@/components/text-link';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { login } from '@/routes';
import { age, store } from '@/routes/register';

type Props = {
    passwordRules: string;
    birth: { birth_month: number; birth_year: number } | null;
    refused: boolean;
    underageMessage: string;
    usernameMin: number;
    usernameMax: number;
    switchHint: string;
};

const monthName = new Intl.DateTimeFormat('en', { month: 'long' });
const MONTHS = Array.from({ length: 12 }, (_, i) =>
    monthName.format(new Date(2000, i, 1)),
);

// Every year from now back 100 years, with no preselected value, so the list hints at no cutoff.
const thisYear = new Date().getFullYear();
const YEARS = Array.from({ length: 101 }, (_, i) => thisYear - i);

const selectClass = `${glassField} [color-scheme:dark]`;

export default function Register({
    passwordRules,
    birth,
    refused,
    underageMessage,
    usernameMin,
    usernameMax,
    switchHint,
}: Props) {
    if (refused) {
        return (
            <>
                <Head title="Register" />
                <SignalCard>
                    <p data-test="underage-message">{underageMessage}</p>
                </SignalCard>
            </>
        );
    }

    if (birth === null) {
        return (
            <>
                <Head title="Register" />
                <Form
                    {...age.form()}
                    disableWhileProcessing
                    className="grid gap-6"
                >
                    {({ processing, errors }) => (
                        <>
                            <p className="text-ink/70">When were you born?</p>
                            <div className="grid grid-cols-2 gap-3">
                                <Field
                                    label="Month"
                                    htmlFor="birth_month"
                                    error={errors.birth_month}
                                >
                                    <select
                                        id="birth_month"
                                        name="birth_month"
                                        required
                                        defaultValue=""
                                        className={selectClass}
                                    >
                                        <option value="" disabled>
                                            Month
                                        </option>
                                        {MONTHS.map((month, i) => (
                                            <option key={month} value={i + 1}>
                                                {month}
                                            </option>
                                        ))}
                                    </select>
                                </Field>
                                <Field
                                    label="Year"
                                    htmlFor="birth_year"
                                    error={errors.birth_year}
                                >
                                    <select
                                        id="birth_year"
                                        name="birth_year"
                                        required
                                        defaultValue=""
                                        className={selectClass}
                                    >
                                        <option value="" disabled>
                                            Year
                                        </option>
                                        {YEARS.map((year) => (
                                            <option key={year} value={year}>
                                                {year}
                                            </option>
                                        ))}
                                    </select>
                                </Field>
                            </div>
                            <InputError
                                message={errors.underage}
                                className="font-mono text-xs text-record"
                            />
                            <RadioKey
                                type="submit"
                                variant="walnut"
                                className="w-full"
                                data-test="age-continue-button"
                            >
                                {processing && <Spinner />}
                                Continue
                            </RadioKey>
                            <p className="text-center text-sm text-ink/70">
                                Already have an account?{' '}
                                <TextLink href={login()}>Log in</TextLink>
                            </p>
                        </>
                    )}
                </Form>
            </>
        );
    }

    return (
        <>
            <Head title="Register" />
            <Form
                {...store.form()}
                resetOnSuccess={['password', 'password_confirmation']}
                disableWhileProcessing
                className="grid gap-5"
            >
                {({ processing, errors }) => (
                    <>
                        <input
                            type="hidden"
                            name="birth_month"
                            value={birth.birth_month}
                        />
                        <input
                            type="hidden"
                            name="birth_year"
                            value={birth.birth_year}
                        />

                        <Field
                            label="Resident name"
                            htmlFor="username"
                            error={errors.username}
                        >
                            <Input
                                id="username"
                                type="text"
                                required
                                autoFocus
                                autoComplete="username"
                                name="username"
                                minLength={usernameMin}
                                maxLength={usernameMax}
                                placeholder={`${usernameMin}–${usernameMax} letters, numbers, or _`}
                                className={glassField}
                                aria-invalid={!!errors.username}
                            />
                        </Field>

                        <Field
                            label="Email address"
                            htmlFor="email"
                            error={errors.email}
                        >
                            <Input
                                id="email"
                                type="email"
                                required
                                autoComplete="email"
                                name="email"
                                placeholder="wren@hushfield.town"
                                className={glassField}
                                aria-invalid={!!errors.email}
                            />
                        </Field>

                        <Field
                            label="Password"
                            htmlFor="password"
                            error={errors.password}
                        >
                            <PasswordInput
                                id="password"
                                required
                                autoComplete="new-password"
                                name="password"
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
                                required
                                autoComplete="new-password"
                                name="password_confirmation"
                                placeholder="••••••••"
                                passwordrules={passwordRules}
                                className={`${glassField} pr-12`}
                                aria-invalid={!!errors.password_confirmation}
                            />
                        </Field>

                        <InputError
                            message={
                                errors.underage ??
                                errors.birth_month ??
                                errors.birth_year
                            }
                            className="font-mono text-xs text-record"
                        />

                        <LauncherSubmit
                            processing={processing}
                            label="Create account"
                            hint={switchHint}
                            data-test="register-user-button"
                        />

                        <p className="text-center text-sm text-ink/70">
                            Already have an account?{' '}
                            <TextLink href={login()}>Log in</TextLink>
                        </p>
                    </>
                )}
            </Form>
        </>
    );
}

Register.layout = {
    title: 'Become a resident',
    description: 'Hearthmore Group has a room ready for you.',
};
