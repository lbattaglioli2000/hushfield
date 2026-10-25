import type { ReactNode } from 'react';
import { useState } from 'react';
import RadioDialog from '@/components/radio/radio-dialog';
import RadioKey from '@/components/radio/radio-key';
import GlassInput from '@/components/radio/glass-input';
import SignalCard, { SignalBars } from '@/components/radio/signal-card';
import ToggleSwitch from '@/components/radio/toggle-switch';
import type { Copy, EventBus, Friend, FriendsList, GameEvents } from '@/game';
import { useGameBus } from '@/hooks/use-game-bus';

/**
 * The Neighbors key, with a count of requests waiting, and the friends list:
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
            <RadioKey
                onClick={() => {
                    setOpen(true);
                    setResult(null);
                    bus.emit('load-friends', {});
                }}
                data-test="open-friends"
            >
                Neighbors
                {waiting > 0 && (
                    <span
                        className="rounded-sm bg-record px-1.5 font-mono text-xs tracking-normal text-bakelite"
                        aria-label={`${waiting} requests waiting`}
                    >
                        {waiting}
                    </span>
                )}
            </RadioKey>

            <RadioDialog
                open={open}
                onOpenChange={setOpen}
                title={copy.friends_title}
                description={summary(friends)}
                data-test="friends-panel"
            >
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
                    <GlassInput
                        value={name}
                        onChange={(event) => setName(event.target.value)}
                        placeholder="Add a friend by name"
                        aria-label="Resident's name"
                        maxLength={32}
                        className="h-10 border border-bakelite/10"
                        data-test="friend-name"
                    />
                    <RadioKey type="submit">Send</RadioKey>
                </form>

                {result && (
                    <SignalCard
                        role="status"
                        tone={result.ok ? 'amber' : 'red'}
                        data-test="friends-result"
                    >
                        {result.message}
                    </SignalCard>
                )}

                <div className="flex max-h-[50vh] flex-col gap-4 overflow-y-auto pr-1">
                    {(friends.incoming.length > 0 ||
                        friends.outgoing.length > 0) && (
                        <Section title="Requests">
                            {friends.incoming.map((request) => (
                                <Row
                                    key={request.id}
                                    name={request.user.username}
                                    detail="would like to be friends"
                                >
                                    <RadioKey
                                        size="sm"
                                        onClick={() =>
                                            bus.emit('answer-friend-request', {
                                                requestId: request.id,
                                                accept: true,
                                            })
                                        }
                                        data-test="accept-request"
                                    >
                                        Accept
                                    </RadioKey>
                                    <RadioKey
                                        size="sm"
                                        variant="glass"
                                        onClick={() =>
                                            bus.emit('answer-friend-request', {
                                                requestId: request.id,
                                                accept: false,
                                            })
                                        }
                                    >
                                        Decline
                                    </RadioKey>
                                </Row>
                            ))}
                            {friends.outgoing.map((request) => (
                                <Row
                                    key={request.id}
                                    name={request.user.username}
                                    detail="waiting for an answer"
                                >
                                    <RadioKey
                                        size="sm"
                                        variant="glass"
                                        onClick={() =>
                                            bus.emit('answer-friend-request', {
                                                requestId: request.id,
                                                accept: false,
                                            })
                                        }
                                    >
                                        Withdraw
                                    </RadioKey>
                                </Row>
                            ))}
                        </Section>
                    )}

                    <Section title="Friends" aside="Sig">
                        {friends.friends.length === 0 ? (
                            <p className="text-bakelite/60">
                                {copy.friends_empty}
                            </p>
                        ) : (
                            friends.friends.map((friend) => (
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
                                            instanceId: location.instance_id,
                                        });
                                    }}
                                    onRemove={() =>
                                        bus.emit('remove-friend', {
                                            userId: friend.id,
                                        })
                                    }
                                />
                            ))
                        )}
                    </Section>
                </div>

                <div className="flex items-center gap-3 border-t border-bakelite/10 pt-3">
                    <ToggleSwitch
                        id="requests-allowed"
                        checked={friends.requests_allowed}
                        onCheckedChange={(allowed) =>
                            bus.emit('set-friend-requests', { allowed })
                        }
                        data-test="requests-allowed"
                    />
                    <label
                        htmlFor="requests-allowed"
                        className="text-xs tracking-[0.15em] text-bakelite/75 uppercase"
                    >
                        Accept friend requests
                    </label>
                </div>
            </RadioDialog>
        </>
    );
}

function summary({ friends }: FriendsList): string {
    const online = friends.filter((friend) => friend.online).length;

    return friends.length === 0
        ? 'Nobody yet.'
        : `${friends.length} ${friends.length === 1 ? 'friend' : 'friends'} · ${online} in Hushfield now`;
}

function Section({
    title,
    aside,
    children,
}: {
    title: string;
    aside?: string;
    children: ReactNode;
}) {
    return (
        <section className="flex flex-col gap-1">
            <h3 className="flex justify-between border-b border-bakelite/10 pb-1 text-[0.65rem] tracking-[0.2em] text-bakelite/55 uppercase">
                {title}
                {aside && <span>{aside}</span>}
            </h3>
            <ul className="flex flex-col">{children}</ul>
        </section>
    );
}

function Row({
    name,
    detail,
    dim = false,
    children,
}: {
    name: string;
    detail: string;
    dim?: boolean;
    children: ReactNode;
}) {
    return (
        <li className="flex min-h-11 items-center justify-between gap-2 py-1">
            <span
                className={`flex min-w-0 flex-col ${dim ? 'opacity-50' : ''}`}
            >
                <span className="truncate font-sans text-base leading-tight font-bold tracking-wide amber-glow uppercase">
                    {name}
                </span>
                <span className="text-xs text-bakelite/60">{detail}</span>
            </span>
            <span className="flex shrink-0 items-center gap-1.5">
                {children}
            </span>
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
        <Row name={friend.username} detail={detail} dim={!friend.online}>
            {location?.can_join && !here && (
                <RadioKey
                    size="sm"
                    onClick={() => onGo(location)}
                    data-test={`go-to-${friend.username}`}
                >
                    Go to
                </RadioKey>
            )}
            {confirming ? (
                <RadioKey size="sm" variant="red" onClick={onRemove}>
                    Remove?
                </RadioKey>
            ) : (
                <RadioKey
                    size="sm"
                    variant="glass"
                    onClick={() => setConfirming(true)}
                >
                    Remove
                </RadioKey>
            )}
            <SignalBars
                level={!friend.online ? 0 : here ? 4 : 3}
                className="ml-2"
            />
        </Row>
    );
}
