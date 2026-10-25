import { Form, Head } from '@inertiajs/react';
import PasswordInput from '@/components/password-input';
import Field from '@/components/radio/field';
import { glassField } from '@/components/radio/glass-input';
import RadioKey from '@/components/radio/radio-key';
import { Spinner } from '@/components/ui/spinner';
import { store } from '@/routes/password/confirm';
import {
    index as confirmOptions,
    store as confirmStore,
} from '@/actions/Laravel/Passkeys/Http/Controllers/PasskeyConfirmationController';
import PasskeyVerify from '@/components/passkey-verify';

export default function ConfirmPassword() {
    return (
        <>
            <Head title="Confirm password" />

            <PasskeyVerify
                routes={{
                    options: confirmOptions(),
                    submit: confirmStore(),
                }}
                label="Confirm with passkey"
                loadingLabel="Confirming..."
                separator="Or confirm with password"
            />

            <Form
                {...store.form()}
                resetOnSuccess={['password']}
                className="grid gap-6"
            >
                {({ processing, errors }) => (
                    <>
                        <Field
                            label="Password"
                            htmlFor="password"
                            error={errors.password}
                        >
                            <PasswordInput
                                id="password"
                                name="password"
                                placeholder="••••••••"
                                autoComplete="current-password"
                                autoFocus
                                className={`${glassField} pr-12`}
                                aria-invalid={!!errors.password}
                            />
                        </Field>

                        <RadioKey
                            type="submit"
                            variant="walnut"
                            className="w-full"
                            disabled={processing}
                            data-test="confirm-password-button"
                        >
                            {processing && <Spinner />}
                            Confirm password
                        </RadioKey>
                    </>
                )}
            </Form>
        </>
    );
}

ConfirmPassword.layout = {
    title: 'Confirm password',
    description:
        'This is a secure area of the application. Please confirm your password before continuing.',
};
