/**
 * The player-facing strings the game and its overlays show, from lang/en/hushfield.php.
 * PlayController sends exactly these keys; add a key in both places.
 */
export type CopyKey =
    | 'entering'
    | 'reconnecting'
    | 'reconnected'
    | 'please_reload'
    | 'please_reload_action'
    | 'session_moved'
    | 'session_moved_action'
    | 'banned'
    | 'banned_action'
    | 'warned'
    | 'instance_closed'
    | 'not_in_room';

export type Copy = Record<CopyKey, string>;
