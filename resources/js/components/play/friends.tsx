import type { ReactNode } from 'react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { Copy, EventBus, Friend, FriendsList, GameEvents } from '@/game';
import { useGameBus } from '@/hooks/use-game-bus';

/**
 * The Friends button, with a count of requests waiting, and the friends list:
 * requests, friends and where they are, adding by name, and the request setting.
 */
export default function Friends({ bus, copy }: { bus: EventBus; copy: Copy }) {
    const [friends, setFriends] = useState<FriendsList | null>(null);
    const [open, setOpen] = useState(false);
    const [name, setName] = useState('');
    const [result, setResult] = useState<GameEvents['friends-result'] | null>(
        null,
    );
    const [instanceId, setInstanceId] = useState<number | null>(null);

    useGameBus(bus, 'friends', setFriends);
    useGameBus(bus, 'friends-result', setResult);
    useGameBus(bus, 'room', () => setOpen(false));
    useGameBus(bus, 'instance', ({ id }) => setInstanceId(id));

    if (!friends) return null;

    const waiting = friends.incoming.length;

    return (
        <>
            <Button
                size="sm"
                variant="secondary"
                onClick={() => {
                    setOpen(true);
                    setResult(null);
                    bus.emit('load-friends', {});
                }}
                data-test="open-friends"
            >
                Friends
                {waiting > 0 && (
                    <span
                        className="ml-1 rounded-full bg-amber-400 px-1.5 text-xs font-semibold text-[#1d2330]"
                        aria-label={`${waiting} requests waiting`}
                    >
                        {waiting}
                    </span>
                )}
            </Button>

            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent data-test="friends-panel">
                    <DialogHeader>
                        <DialogTitle>{copy.friends_title}</DialogTitle>
                        <DialogDescription>
                            {summary(friends)}
                        </DialogDescription>
                    </DialogHeader>

                    <form
                        className="flex gap-2"
                        onSubmit={(event) => {
                            event.preventDefault();

                            if (name.trim() === '') return;

                            bus.emit('send-friend-request', {
                                username: name.trim(),
                            });
                            setName('');
                        }}
                    >
                        <Input
                            value={name}
                            onChange={(event) => setName(event.target.value)}
                            placeholder="Add a friend by name"
                            aria-label="Resident's name"
                            maxLength={32}
                            data-test="friend-name"
                        />
                        <Button type="submit" variant="secondary">
                            Send
                        </Button>
                    </form>

                    {result && (
                        <p
                            role="status"
                            className={`rounded-md px-3 py-2 text-sm text-white ${result.ok ? 'bg-emerald-950/80' : 'bg-red-950/80'}`}
                            data-test="friends-result"
                        >
                            {result.message}
                        </p>
                    )}

                    <div className="grid max-h-[50vh] gap-4 overflow-y-auto">
                        {(friends.incoming.length > 0 ||
                            friends.outgoing.length > 0) && (
                            <section className="grid gap-2">
                                <h3 className="text-sm font-medium">
                                    Requests
                                </h3>
                                <ul className="grid gap-1">
                                    {friends.incoming.map((request) => (
                                        <Row
                                            key={request.id}
                                            name={request.user.username}
                                            detail="would like to be friends"
                                        >
                                            <Button
                                                size="sm"
                                                onClick={() =>
                                                    bus.emit(
                                                        'answer-friend-request',
                                                        {
                                                            requestId:
                                                                request.id,
                                                            accept: true,
                                                        },
                                                    )
                                                }
                                                data-test="accept-request"
                                            >
                                                Accept
                                            </Button>
                                            <Button
                                                size="sm"
                                                variant="ghost"
                                                onClick={() =>
                                                    bus.emit(
                                                        'answer-friend-request',
                                                        {
                                                            requestId:
                                                                request.id,
                                                            accept: false,
                                                        },
                                                    )
                                                }
                                            >
                                                Decline
                                            </Button>
                                        </Row>
                                    ))}
                                    {friends.outgoing.map((request) => (
                                        <Row
                                            key={request.id}
                                            name={request.user.username}
                                            detail="waiting for an answer"
                                        >
                                            <Button
                                                size="sm"
                                                variant="ghost"
                                                onClick={() =>
                                                    bus.emit(
                                                        'answer-friend-request',
                                                        {
                                                            requestId:
                                                                request.id,
                                                            accept: false,
                                                        },
                                                    )
                                                }
                                            >
                                                Withdraw
                                            </Button>
                                        </Row>
                                    ))}
                                </ul>
                            </section>
                        )}

                        <section className="grid gap-2">
                            <h3 className="text-sm font-medium">Friends</h3>
                            {friends.friends.length === 0 ? (
                                <p className="text-sm text-muted-foreground">
                                    {copy.friends_empty}
                                </p>
                            ) : (
                                <ul className="grid gap-1">
                                    {friends.friends.map((friend) => (
                                        <FriendRow
                                            key={friend.id}
                                            friend={friend}
                                            here={
                                                friend.location?.instance_id ===
                                                instanceId
                                            }
                                            onGo={(location) => {
                                                setOpen(false);
                                                bus.emit('travel', {
                                                    slug: location.room,
                                                    instanceId:
                                                        location.instance_id,
                                                });
                                            }}
                                            onRemove={() =>
                                                bus.emit('remove-friend', {
                                                    userId: friend.id,
                                                })
                                            }
                                        />
                                    ))}
                                </ul>
                            )}
                        </section>
                    </div>

                    <Label className="flex items-center gap-2 text-sm font-normal">
                        <Checkbox
                            checked={friends.requests_allowed}
                            onCheckedChange={(checked) =>
                                bus.emit('set-friend-requests', {
                                    allowed: checked === true,
                                })
                            }
                            data-test="requests-allowed"
                        />
                        Accept friend requests
                    </Label>
                </DialogContent>
            </Dialog>
        </>
    );
}

function summary({ friends }: FriendsList): string {
    const online = friends.filter((friend) => friend.online).length;

    return friends.length === 0
        ? 'Nobody yet.'
        : `${friends.length} ${friends.length === 1 ? 'friend' : 'friends'} · ${online} in Hushfield now`;
}

function Row({
    name,
    detail,
    children,
}: {
    name: string;
    detail: string;
    children: ReactNode;
}) {
    return (
        <li className="flex items-center justify-between gap-2 rounded-lg border px-3 py-2">
            <span className="min-w-0 truncate">
                {name}
                <span className="ml-2 text-xs text-muted-foreground">
                    {detail}
                </span>
            </span>
            <span className="flex shrink-0 gap-1">{children}</span>
        </li>
    );
}

function FriendRow({
    friend,
    here,
    onGo,
    onRemove,
}: {
    friend: Friend;
    here: boolean;
    onGo: (location: NonNullable<Friend['location']>) => void;
    onRemove: () => void;
}) {
    const [confirming, setConfirming] = useState(false);
    const { location } = friend;
    const detail = !friend.online
        ? 'away from Hushfield'
        : here
          ? 'right here'
          : (location?.label ?? 'between rooms');

    return (
        <Row name={friend.username} detail={detail}>
            <span
                aria-hidden
                className={`mr-1 size-2 self-center rounded-full ${friend.online ? 'bg-emerald-400' : 'bg-stone-400'}`}
            />
            {location?.can_join && !here && (
                <Button
                    size="sm"
                    onClick={() => onGo(location)}
                    data-test={`go-to-${friend.username}`}
                >
                    Go to
                </Button>
            )}
            {confirming ? (
                <Button size="sm" variant="destructive" onClick={onRemove}>
                    Remove?
                </Button>
            ) : (
                <Button
                    size="sm"
                    variant="ghost"
                    onClick={() => setConfirming(true)}
                >
                    Remove
                </Button>
            )}
        </Row>
    );
}
