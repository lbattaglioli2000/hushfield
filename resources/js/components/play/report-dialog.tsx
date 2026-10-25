import { useEffect, useState } from 'react';
import RadioDialog from '@/components/radio/radio-dialog';
import RadioKey from '@/components/radio/radio-key';
import SignalCard from '@/components/radio/signal-card';
import type { EventBus, GameEvents, ReportReasonOption } from '@/game';
import { useGameBus } from '@/hooks/use-game-bus';

export type ReportTarget = GameEvents['player-selected'];

export default function ReportDialog({
    bus,
    reasons,
    target,
    onClose,
}: {
    bus: EventBus;
    reasons: ReportReasonOption[];
    /** Who is being reported; the dialog is open while set. */
    target: ReportTarget | null;
    onClose: () => void;
}) {
    const [reason, setReason] = useState('');
    const [result, setResult] = useState<GameEvents['report-result'] | null>(
        null,
    );
    const [sending, setSending] = useState(false);

    useEffect(() => {
        setReason('');
        setResult(null);
    }, [target]);
    useGameBus(bus, 'report-result', (next) => {
        setSending(false);
        setResult(next);
    });

    return (
        <RadioDialog
            open={target !== null}
            onOpenChange={(open) => !open && onClose()}
            title={`Report ${target?.username ?? ''}`}
            description={
                <>
                    Moderators will review this player&apos;s recent messages.
                    They won&apos;t be told who reported them.
                </>
            }
            data-test="report-dialog"
        >
            {result?.ok ? (
                <SignalCard role="status" tone="amber">
                    {result.message}
                </SignalCard>
            ) : (
                <div className="grid gap-3">
                    {target?.body && (
                        <blockquote className="rounded-sm border-l-4 border-amber/60 bg-bakelite/[0.04] px-3 py-2 text-amber/90">
                            {target.body}
                        </blockquote>
                    )}
                    <fieldset className="grid gap-1">
                        <legend className="mb-2 text-[0.65rem] tracking-[0.2em] text-bakelite/55 uppercase">
                            What is wrong?
                        </legend>
                        {reasons.map((option) => (
                            <label
                                key={option.value}
                                className="flex cursor-pointer items-center gap-3 rounded-sm px-2 py-1.5 hover:bg-amber/10 has-checked:text-amber"
                            >
                                <input
                                    type="radio"
                                    name="reason"
                                    value={option.value}
                                    checked={reason === option.value}
                                    onChange={() => setReason(option.value)}
                                    className="accent-amber"
                                />
                                {option.label}
                            </label>
                        ))}
                    </fieldset>
                    {result && !result.ok && (
                        <SignalCard role="status">{result.message}</SignalCard>
                    )}
                </div>
            )}

            <div className="flex justify-end">
                {result?.ok ? (
                    <RadioKey onClick={onClose}>Close</RadioKey>
                ) : (
                    <RadioKey
                        variant="red"
                        disabled={!reason || sending}
                        onClick={() => {
                            if (!target) return;
                            setSending(true);
                            bus.emit('send-report', {
                                userId: target.userId,
                                messageId: target.messageId,
                                reason,
                            });
                        }}
                        data-test="report-submit"
                    >
                        Send report
                    </RadioKey>
                )}
            </div>
        </RadioDialog>
    );
}
