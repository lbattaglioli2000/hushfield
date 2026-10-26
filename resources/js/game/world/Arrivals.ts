import type { PathState, Tile } from '../protocol/types';
import type { IsoMap, MapDoor, MapFixture } from './IsoMap';

/**
 * What happens when the player's own walk ends on a door or a fixture: each
 * walk goes through a door, or uses a fixture, at most once. A fixture is
 * used on arrival, so tapping one just walks there.
 */
export class Arrivals {
    /** The path whose arrival was already acted on. */
    private handled: PathState | null = null;

    constructor(
        private readonly on: {
            door(door: MapDoor): void;
            fixture(fixture: MapFixture): void;
        },
    ) {}

    /** Called every frame the player's own avatar stands still at the end of `walk`. */
    arrived(map: IsoMap, walk: PathState, tile: Tile): void {
        if (this.handled === walk) return;

        // The server keeps fixtures off doors, so a tile is at most one of them.
        const door = map.doorAt(tile);
        const fixture = door ? null : map.fixtureAt(tile);

        if (door) this.on.door(door);
        if (fixture) this.on.fixture(fixture);
        if (door || fixture) this.handled = walk;
    }
}
