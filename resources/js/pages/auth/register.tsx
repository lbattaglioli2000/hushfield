import { Form, Head } from '@inertiajs/react';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { login } from '@/routes';
import { age, store } from '@/routes/register';

type Props = {
    passwordRules: string;
    birth: { birth_month: number; birth_year: number } | null;
    refused: boolean;
    underageMessage: string;
};

const MONTHS = [
    'January',
    'February',
    'March',
    'April',
    'May',
    'June',
    'July',
    'August',
    'September',
    'October',
    'November',
    'December',
];

// Every year from now back 100 years, with no preselected value, so the list hints at no cutoff.
const thisYear = new Date().getFullYear();
const YEARS = Array.from({ length: 101 }, (_, i) => thisYear - i);

const selectClass =
    'h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50';

export default function Register({
    passwordRules,
    birth,
    refused,
    underageMessage,
}: Props) {
    if (refused) {
        return (
            <>
                <Head title="Register" />
                <p className="text-center text-sm" data-test="underage-message">
                    {underageMessage}
                </p>
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
                    className="flex flex-col gap-6"
                >
                    {({ processing, errors }) => (
                        <div className="grid gap-6">
                            <p className="text-sm text-muted-foreground">
                                When were you born?
                            </p>
                            <div className="grid grid-cols-2 gap-3">
                                <div className="grid gap-2">
                                    <Label htmlFor="birth_month">Month</Label>
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
                                    <InputError message={errors.birth_month} />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="birth_year">Year</Label>
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
                                    <InputError message={errors.birth_year} />
                                </div>
                            </div>
                            <InputError message={errors.underage} />
                            <Button
                                type="submit"
                                className="w-full"
                                data-test="age-continue-button"
                            >
                                {processing && <Spinner />}
                                Continue
                            </Button>
                            <div className="text-center text-sm text-muted-foreground">
                                Already have an account?{' '}
                                <TextLink href={login()}>Log in</TextLink>
                            </div>
                        </div>
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
                className="flex flex-col gap-6"
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
                        <div className="grid gap-6">
                            <div className="grid gap-2">
                                <Label htmlFor="username">Username</Label>
                                <Input
                                    id="username"
                                    type="text"
                                    required
                                    autoFocus
                                    tabIndex={1}
                                    autoComplete="username"
                                    name="username"
                                    minLength={3}
                                    maxLength={16}
                                    placeholder="3–16 letters, numbers, or _"
                                />
                                <InputError message={errors.username} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="email">Email address</Label>
                                <Input
                                    id="email"
                                    type="email"
                                    required
                                    tabIndex={2}
                                    autoComplete="email"
                                    name="email"
                                    placeholder="email@example.com"
                                />
                                <InputError message={errors.email} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="password">Password</Label>
                                <PasswordInput
                                    id="password"
                                    required
                                    tabIndex={3}
                                    autoComplete="new-password"
                                    name="password"
                                    placeholder="Password"
                                    passwordrules={passwordRules}
                                />
                                <InputError message={errors.password} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="password_confirmation">
                                    Confirm password
                                </Label>
                                <PasswordInput
                                    id="password_confirmation"
                                    required
                                    tabIndex={4}
                                    autoComplete="new-password"
                                    name="password_confirmation"
                                    placeholder="Confirm password"
                                    passwordrules={passwordRules}
                                />
                                <InputError
                                    message={errors.password_confirmation}
                                />
                            </div>

                            <InputError
                                message={
                                    errors.underage ??
                                    errors.birth_month ??
                                    errors.birth_year
                                }
                            />

                            <Button
                                type="submit"
                                className="mt-2 w-full"
                                tabIndex={5}
                                data-test="register-user-button"
                            >
                                {processing && <Spinner />}
                                Create account
                            </Button>
                        </div>

                        <div className="text-center text-sm text-muted-foreground">
                            Already have an account?{' '}
                            <TextLink href={login()} tabIndex={6}>
                                Log in
                            </TextLink>
                        </div>
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
