import { useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import type { EventBus, GameEvents, ReportReasonOption } from '@/game';
import { useGameBus } from '@/hooks/use-game-bus';

export default function ReportDialog({
    bus,
    reasons,
}: {
    bus: EventBus;
    reasons: ReportReasonOption[];
}) {
    const [target, setTarget] = useState<GameEvents['report-target'] | null>(
        null,
    );
    const [reason, setReason] = useState('');
    const [result, setResult] = useState<GameEvents['report-result'] | null>(
        null,
    );
    const [sending, setSending] = useState(false);

    useGameBus(bus, 'report-target', (next) => {
        setTarget(next);
        setReason('');
        setResult(null);
    });
    useGameBus(bus, 'report-result', (next) => {
        setSending(false);
        setResult(next);
    });

    return (
        <Dialog
            open={target !== null}
            onOpenChange={(open) => !open && setTarget(null)}
        >
            <DialogContent data-test="report-dialog">
                <DialogHeader>
                    <DialogTitle>Report {target?.username}</DialogTitle>
                    <DialogDescription>
                        Moderators will review this player&apos;s recent
                        messages. They won&apos;t be told who reported them.
                    </DialogDescription>
                </DialogHeader>

                {result?.ok ? (
                    <p className="text-sm">{result.message}</p>
                ) : (
                    <div className="grid gap-3">
                        {target?.body && (
                            <blockquote className="rounded-md border-l-4 bg-muted px-3 py-2 text-sm">
                                {target.body}
                            </blockquote>
                        )}
                        <fieldset className="grid gap-2">
                            <legend className="mb-1 text-sm font-medium">
                                What is wrong?
                            </legend>
                            {reasons.map((option) => (
                                <label
                                    key={option.value}
                                    className="flex items-center gap-2 text-sm"
                                >
                                    <input
                                        type="radio"
                                        name="reason"
                                        value={option.value}
                                        checked={reason === option.value}
                                        onChange={() => setReason(option.value)}
                                    />
                                    {option.label}
                                </label>
                            ))}
                        </fieldset>
                        {result && !result.ok && (
                            <p className="text-sm text-destructive">
                                {result.message}
                            </p>
                        )}
                    </div>
                )}

                <DialogFooter>
                    {result?.ok ? (
                        <Button onClick={() => setTarget(null)}>Close</Button>
                    ) : (
                        <Button
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
                        </Button>
                    )}
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
