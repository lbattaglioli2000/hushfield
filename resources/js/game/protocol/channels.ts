/**
 * Broadcast channel and event names, as app/Events broadcasts them. Room instance
 * channels are not built here: the snapshot names them (`instance.channel`).
 */
export function sessionChannel(token: string): string {
    return `world-session.${token}`;
}

/** On the private session channel: things that happen to this player wherever they are. */
export const SESSION_EVENTS = {
    superseded: '.SessionSuperseded',
    accessRevoked: '.WorldAccessRevoked',
    warned: '.PlayerWarned',
    instanceReassigned: '.InstanceReassigned',
    friendsUpdated: '.FriendsUpdated',
} as const;

/** On a room instance's presence channel. */
export const ROOM_EVENTS = {
    playerJoined: '.PlayerJoined',
    playerLeft: '.PlayerLeft',
    playerMoved: '.PlayerMoved',
    chatMessageSent: '.ChatMessageSent',
    furnitureChanged: '.FurnitureChanged',
    rareCatchLanded: '.RareCatchLanded',
} as const;

/** Client-to-client whispers on the presence channel; the server never sees them. */
export const WHISPERS = {
    idle: 'idle',
} as const;
