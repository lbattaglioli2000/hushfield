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
    | 'not_in_room'
    | 'shop_title'
    | 'shop_intro'
    | 'shop_closed'
    | 'inventory_title'
    | 'inventory_empty'
    | 'stipend_offer'
    | 'members_only'
    | 'residences_title'
    | 'residences_intro'
    | 'residences_empty'
    | 'decorate_hint'
    | 'friends_title'
    | 'friends_empty'
    | 'town_map_title'
    | 'town_map_intro'
    | 'town_map_unplaced'
    | 'chat_placeholder'
    | 'on_air_host';

/** `on_air` is the one list: the lines the ON AIR ticker picks from. */
export type Copy = Record<CopyKey, string> & { on_air: string[] };
