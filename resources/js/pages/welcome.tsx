import { Head, Link } from '@inertiajs/react';
import BakelitePanel from '@/components/radio/bakelite-panel';
import { keyVariants } from '@/components/radio/radio-key';
import { login, register } from '@/routes';

type Props = {
    copy: {
        landing_tagline: string;
        landing_intro: string;
        landing_walk: string;
        landing_home: string;
        landing_talk: string;
        landing_sign_off: string;
    };
};

/** The landing page for visitors. Signed-in residents never see it: the server sends them to play. */
export default function Welcome({ copy }: Props) {
    const points = [copy.landing_walk, copy.landing_home, copy.landing_talk];

    return (
        <>
            <Head title="Hushfield" />
            <div className="flex min-h-svh items-center justify-center wood p-6 md:p-10">
                <BakelitePanel className="grid w-full max-w-5xl gap-8 p-8 md:grid-cols-[1fr_16rem] md:p-10">
                    <main className="flex flex-col gap-6">
                        <p className="text-xs font-semibold tracking-[0.2em] text-ink/70 uppercase">
                            The Listener, Model 3B
                        </p>
                        <h1 className="text-7xl leading-[0.85] font-bold tracking-tight text-ink uppercase md:text-8xl">
                            Hushfield
                        </h1>
                        <div className="flex overflow-hidden rounded-md glass">
                            <span className="flex items-center bg-record px-3 text-xs font-bold tracking-[0.2em] whitespace-nowrap text-bakelite uppercase">
                                On air
                            </span>
                            <p className="px-4 py-2.5 font-mono text-sm tracking-wide amber-glow uppercase">
                                {copy.landing_tagline}
                            </p>
                        </div>
                        <p className="max-w-prose text-lg leading-relaxed text-ink/85">
                            {copy.landing_intro}
                        </p>
                        <ul className="grid gap-3">
                            {points.map((point) => (
                                <li
                                    key={point}
                                    className="flex gap-3 text-base text-ink/80"
                                >
                                    <span
                                        aria-hidden
                                        className="mt-2 size-2 shrink-0 rounded-full bg-amber shadow-[0_0_6px_rgb(242_184_75/0.8)]"
                                    />
                                    {point}
                                </li>
                            ))}
                        </ul>
                        <div className="mt-2 flex flex-wrap items-center gap-3">
                            <Link
                                href={register()}
                                className={keyVariants({ variant: 'red' })}
                                data-test="switch-on"
                            >
                                Switch on
                            </Link>
                            <Link
                                href={login()}
                                className={keyVariants({ variant: 'bakelite' })}
                                data-test="log-in"
                            >
                                Log in
                            </Link>
                        </div>
                        <p className="font-mono text-xs text-ink/60">
                            {copy.landing_sign_off}
                        </p>
                    </main>
                    <div aria-hidden className="hidden flex-col gap-4 md:flex">
                        <div className="flex-1 rounded-xl grille" />
                        <div className="rounded-md bg-[linear-gradient(135deg,#e4e3df,#a9a7a1)] px-3 py-2 text-center text-xs font-bold tracking-[0.3em] text-ink/80 uppercase shadow-[inset_0_1px_0_white,0_2px_3px_rgb(0_0_0/0.25)]">
                            Hushfield · FM
                        </div>
                    </div>
                </BakelitePanel>
            </div>
        </>
    );
}
