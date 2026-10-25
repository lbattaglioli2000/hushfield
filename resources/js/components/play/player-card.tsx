import { useState } from 'react';
import ReportDialog from '@/components/play/report-dialog';
import type { ReportTarget } from '@/components/play/report-dialog';
import RadioDialog from '@/components/radio/radio-dialog';
import RadioKey from '@/components/radio/radio-key';
import SignalCard from '@/components/radio/signal-card';
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
            <RadioDialog
                open={selected !== null}
                onOpenChange={(open) => !open && setSelected(null)}
                band="Resident"
                title={selected?.username}
                description="A resident of Hushfield."
                className="sm:max-w-sm"
                data-test="player-card"
            >
                {message && <SignalCard tone="amber">{message}</SignalCard>}

                <div className="flex justify-between gap-2">
                    <RadioKey
                        variant="glass"
                        onClick={() => {
                            setReporting(selected);
                            setSelected(null);
                        }}
                        data-test="report-player"
                    >
                        Report
                    </RadioKey>
                    {status ? (
                        <RadioKey disabled>{status}</RadioKey>
                    ) : (
                        <RadioKey
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
                        </RadioKey>
                    )}
                </div>
            </RadioDialog>
            <ReportDialog
                bus={bus}
                reasons={reasons}
                target={reporting}
                onClose={() => setReporting(null)}
            />
        </>
    );
}
