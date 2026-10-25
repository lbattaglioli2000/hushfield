import type { ReactNode } from 'react';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { cn } from '@/lib/utils';

/** A panel of the set: dark glass in a walnut bezel, with an amber heading. */
export default function RadioDialog({
    open,
    onOpenChange,
    band,
    title,
    description,
    children,
    className,
    'data-test': dataTest,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    /** The etched line above the title. */
    band?: ReactNode;
    title: ReactNode;
    description?: ReactNode;
    children: ReactNode;
    className?: string;
    'data-test'?: string;
}) {
    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent
                className={cn(
                    'gap-4 rounded-xl border-[6px] border-walnut-deep glass bg-glass p-5 font-mono text-sm text-bakelite/85 sm:max-w-lg [&>button:last-child]:text-amber [&>button:last-child]:ring-amber [&>button:last-child]:ring-offset-glass',
                    className,
                )}
                // Radix expects a description; say there is none rather than leave it dangling.
                {...(description ? {} : { 'aria-describedby': undefined })}
                data-test={dataTest}
            >
                <DialogHeader className="gap-1 border-b border-bakelite/10 pb-3 text-left sm:text-left">
                    {band && (
                        <p className="text-[0.65rem] tracking-[0.2em] text-bakelite/55 uppercase">
                            {band}
                        </p>
                    )}
                    <DialogTitle className="font-sans text-2xl font-bold tracking-wide amber-glow uppercase">
                        {title}
                    </DialogTitle>
                    {description && (
                        <DialogDescription className="text-xs leading-relaxed text-bakelite/70">
                            {description}
                        </DialogDescription>
                    )}
                </DialogHeader>
                {children}
            </DialogContent>
        </Dialog>
    );
}
