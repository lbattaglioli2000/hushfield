import type { EventBus } from './bus';
import type { Copy } from './copy';
import { userMessage } from './net/http';
import type { WorldNet } from './net/WorldNet';
import type { FriendsUpdate } from './protocol/types';

/**
 * Connects the friends overlays to the server. Every change answers with the
 * whole list, sent on as 'friends'. Returns the cleanup and a way to load the
 * list once the world session exists.
 */
export function connectSocial(
    net: WorldNet,
    bus: EventBus,
    copy: Copy,
): { loadFriends(): void; dispose(): void } {
    const fail = (error: unknown) =>
        bus.emit('friends-result', {
            ok: false,
            message: userMessage(error, copy),
        });
    const applied = ({ message, friends }: FriendsUpdate) => {
        bus.emit('friends', friends);

        if (message) bus.emit('friends-result', { ok: true, message });
    };
    const loadFriends = () =>
        net.friends().then((friends) => bus.emit('friends', friends));

    const cleanups = [
        bus.on('load-friends', () => {
            loadFriends().catch(fail);
        }),
        bus.on('send-friend-request', ({ username }) => {
            net.sendFriendRequest(username).then(applied).catch(fail);
        }),
        bus.on('answer-friend-request', ({ requestId, accept }) => {
            net.answerFriendRequest(requestId, accept)
                .then(applied)
                .catch(fail);
        }),
        bus.on('remove-friend', ({ userId }) => {
            net.removeFriend(userId).then(applied).catch(fail);
        }),
        bus.on('set-friend-requests', ({ allowed }) => {
            net.setFriendRequests(allowed).then(applied).catch(fail);
        }),
    ];

    return {
        loadFriends() {
            loadFriends().catch(() => undefined);
        },
        dispose() {
            cleanups.forEach((cleanup) => cleanup());
        },
    };
}
