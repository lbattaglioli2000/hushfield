import { Form, Head } from '@inertiajs/react';
import RadioKey from '@/components/radio/radio-key';
import SignalCard from '@/components/radio/signal-card';
import TextLink from '@/components/text-link';
import { Spinner } from '@/components/ui/spinner';
import { logout } from '@/routes';
import { send } from '@/routes/verification';

export default function VerifyEmail({ status }: { status?: string }) {
    return (
        <>
            <Head title="Email verification" />

            {status === 'verification-link-sent' && (
                <SignalCard tone="amber" className="mb-6">
                    A new verification link has been sent to the email address
                    you provided during registration.
                </SignalCard>
            )}

            <Form {...send.form()} className="grid gap-5 text-center">
                {({ processing }) => (
                    <>
                        <RadioKey
                            type="submit"
                            variant="walnut"
                            className="w-full"
                            disabled={processing}
                        >
                            {processing && <Spinner />}
                            Resend verification email
                        </RadioKey>

                        <TextLink href={logout()} className="mx-auto text-sm">
                            Log out
                        </TextLink>
                    </>
                )}
            </Form>
        </>
    );
}

VerifyEmail.layout = {
    title: 'Email verification',
    description:
        'Please verify your email address by clicking on the link we just emailed to you.',
};
