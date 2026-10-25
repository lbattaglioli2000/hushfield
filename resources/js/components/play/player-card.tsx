import { useState } from 'react';
import ReportDialog from '@/components/play/report-dialog';
import type { ReportTarget } from '@/components/play/report-dialog';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import type { EventBus, FriendsList, ReportReasonOption } from '@/game';
import { useGameBus } from '@/hooks/use-game-bus';

/**
 * What tapping another resident opens: a friend request, or a report.
 */
export default function PlayerCard({
    bus,
    reasons,
}: {
    bus: EventBus;
    reasons: ReportReasonOption[];
}) {
    const [selected, setSelected] = useState<ReportTarget | null>(null);
    const [reporting, setReporting] = useState<ReportTarget | null>(null);
    const [friends, setFriends] = useState<FriendsList | null>(null);
    const [message, setMessage] = useState<string | null>(null);

    useGameBus(bus, 'player-selected', (player) => {
        setSelected(player);
        setMessage(null);
    });
    useGameBus(bus, 'friends', setFriends);
    useGameBus(bus, 'friends-result', ({ message }) => setMessage(message));

    const id = selected?.userId;
    const status = !friends
        ? null
        : friends.friends.some((friend) => friend.id === id)
          ? 'Friends'
          : friends.outgoing.some((request) => request.user.id === id)
            ? 'Request sent'
            : null;
    const theirRequest = friends?.incoming.find(
        (request) => request.user.id === id,
    );

    return (
        <>
            <Dialog
                open={selected !== null}
                onOpenChange={(open) => !open && setSelected(null)}
            >
                <DialogContent className="sm:max-w-sm" data-test="player-card">
                    <DialogHeader>
                        <DialogTitle>{selected?.username}</DialogTitle>
                        <DialogDescription>
                            A resident of Hushfield.
                        </DialogDescription>
                    </DialogHeader>

                    {message && <p className="text-sm">{message}</p>}

                    <DialogFooter className="gap-2 sm:justify-between">
                        <Button
                            variant="ghost"
                            onClick={() => {
                                setReporting(selected);
                                setSelected(null);
                            }}
                            data-test="report-player"
                        >
                            Report
                        </Button>
                        {status ? (
                            <Button variant="secondary" disabled>
                                {status}
                            </Button>
                        ) : (
                            <Button
                                onClick={() =>
                                    theirRequest
                                        ? bus.emit('answer-friend-request', {
                                              requestId: theirRequest.id,
                                              accept: true,
                                          })
                                        : selected &&
                                          bus.emit('send-friend-request', {
                                              username: selected.username,
                                          })
                                }
                                data-test="add-friend"
                            >
                                {theirRequest
                                    ? 'Accept friend request'
                                    : 'Add friend'}
                            </Button>
                        )}
                    </DialogFooter>
                </DialogContent>
            </Dialog>
            <ReportDialog
                bus={bus}
                reasons={reasons}
                target={reporting}
                onClose={() => setReporting(null)}
            />
        </>
    );
}
