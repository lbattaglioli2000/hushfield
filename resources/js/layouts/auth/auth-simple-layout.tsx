import { Link } from '@inertiajs/react';
import BakelitePanel from '@/components/radio/bakelite-panel';
import { home } from '@/routes';
import type { AuthLayoutProps } from '@/types';

/** The launcher: one bakelite card on the walnut, for switching on and everything around it. */
export default function AuthSimpleLayout({
    children,
    title,
    description,
}: AuthLayoutProps) {
    return (
        <div className="flex min-h-svh items-center justify-center wood p-6 md:p-10">
            <BakelitePanel className="w-full max-w-md p-8">
                <div className="mb-6 flex items-center justify-between gap-4">
                    <Link
                        href={home()}
                        className="text-xs font-semibold tracking-[0.2em] text-ink uppercase outline-none hover:text-walnut focus-visible:ring-2 focus-visible:ring-amber"
                    >
                        Hushfield · Launcher
                    </Link>
                    <span className="rounded-sm bg-glass px-2 py-1 font-mono text-[0.65rem] tracking-[0.2em] text-bakelite uppercase">
                        Model 3B
                    </span>
                </div>
                <div className="mb-6 grid gap-2">
                    <h1 className="text-4xl leading-none font-bold tracking-tight text-ink uppercase">
                        {title}
                    </h1>
                    {description && (
                        <p className="text-base text-ink/70">{description}</p>
                    )}
                </div>
                {children}
            </BakelitePanel>
        </div>
    );
}
