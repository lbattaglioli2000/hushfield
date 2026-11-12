import type {
    ArtAnimation,
    Item,
    PlacedFurniture,
    RoomPiece,
    Tile,
} from '../protocol/types';
import type { Grid } from './astar';
import { isWalkable } from './astar';
import type { Footprint } from './footprint';
import {
    covers,
    distanceTo,
    mirrored,
    sameShape,
    tilesOf,
    turned,
} from './footprint';
import { TILE_HEIGHT } from './iso';

/* How furniture is sized, turned and drawn, and its art: resources/maps/README.md. */

/**
 * Facings. A piece turned 0 faces the viewer's lower left (+y). Each turn is a
 * quarter turn clockwise seen from above: 1 faces the upper left (−x), 2 the
 * upper right (−y), 3 the lower right (+x).
 */
export const FACINGS = 4;

/** Tiles each room piece takes in a map's props tileset: its four facings, then the same switched on. */
export const PROP_TILES = 8;

/**
 * Anything drawn as furniture, its back corner on tile (x, y), turned
 * `rotation` quarter turns: a piece placed in a home, or one of a room's own
 * pieces, placed in its map (see roomPieces).
 */
export type FurniturePiece = Pick<PlacedFurniture, 'x' | 'y' | 'rotation'> & {
    item: Pick<Item, 'slug' | 'swatch' | 'furniture'>;
    /** For a room piece placed switched on, what plays over its art, by the facing drawn over. */
    animations?: Record<string, ArtAnimation>;
};

/** How big a furniture item is, and its shape (a Footprint mask) if it is not a plain block, before any turn. */
export type FurnitureSize = {
    wide: number;
    deep: number;
    shape?: string[] | null;
};

/**
 * The tiles an item covers with its back corner at (x, y), turned `rotation`
 * quarter turns: a quarter turn swaps how wide and how deep it is, and turns
 * its shape. Mirrors Item::footprintAt.
 */
export function footprintOf(
    size: FurnitureSize,
    x: number,
    y: number,
    rotation: number,
): Footprint {
    return turned(
        { x, y, wide: size.wide, deep: size.deep, mask: size.shape },
        rotation,
    );
}

/** A placed piece's footprint. Items from before footprints existed stand on one tile. */
export function placedFootprint(piece: FurniturePiece): Footprint {
    return footprintOf(
        piece.item.furniture ?? { wide: 1, deep: 1 },
        piece.x,
        piece.y,
        piece.rotation,
    );
}

/** Rugs and other floor pieces are walked over; everything else is walked around. */
export function blocksWalking(piece: FurniturePiece): boolean {
    return !piece.item.furniture?.floor;
}

/**
 * Where players may walk in a home: the map's grid minus the tiles under
 * blocking furniture, as the server checks it. The map's grid is left as is.
 */
export function withFurniture(grid: Grid, placed: FurniturePiece[]): Grid {
    const walkable = grid.walkable.slice();

    for (const piece of placed) {
        if (!blocksWalking(piece)) continue;

        for (const [tx, ty] of tilesOf(placedFootprint(piece))) {
            if (tx < grid.width && ty < grid.height) {
                walkable[ty * grid.width + tx] = 0;
            }
        }
    }

    return { ...grid, walkable };
}

/**
 * A room's own pieces, from the objects on its map's "props" layer: tiles of
 * the props tileset (first GID `firstGid`), each pinned by the footprint's
 * front corner, its facing the tile's place among its piece's first four;
 * the next four are the same switched on, playing its animations.
 * Mirrors TiledMapParser::props, which checked them when the map was imported.
 */
export function roomPieces(
    objects: Array<{ gid?: number; x?: number; y?: number }>,
    firstGid: number | null,
    registry: Array<RoomPiece | null>,
): FurniturePiece[] {
    if (firstGid === null) return [];

    return objects.flatMap((object) => {
        const tile = (object.gid ?? 0) - firstGid;
        const piece =
            tile >= 0 ? registry[Math.floor(tile / PROP_TILES)] : null;

        if (!piece) return [];

        const rotation = tile % FACINGS;
        const on = tile % PROP_TILES >= FACINGS;
        const [wide, deep] =
            rotation % 2 === 0
                ? [piece.wide, piece.deep]
                : [piece.deep, piece.wide];

        return [
            {
                x: Math.round((object.x ?? 0) / TILE_HEIGHT) - wide,
                y: Math.round((object.y ?? 0) / TILE_HEIGHT) - deep,
                rotation,
                ...(on && piece.animations
                    ? { animations: piece.animations }
                    : {}),
                item: {
                    slug: piece.slug,
                    swatch: null,
                    furniture: {
                        wide: piece.wide,
                        deep: piece.deep,
                        height: TILE_HEIGHT,
                        floor: false,
                        shape: null,
                        // Room pieces hold no books yet: the library's shelves are fixtures.
                        holds_books: null,
                        art: piece.art,
                    },
                },
            },
        ];
    });
}

/** Whether a placed piece can be switched on: its item has an animation to play. */
export function switches(piece: FurniturePiece): boolean {
    return Object.keys(piece.item.furniture?.animations ?? {}).length > 0;
}

/** Whether a placed piece is a bookshelf: its item holds books. */
export function holdsBooks(piece: FurniturePiece): boolean {
    return (piece.item.furniture?.holds_books ?? null) !== null;
}

/**
 * Where to stand to use a piece: the walkable tiles a step from it (its
 * footprint is blocked), nearest `from` first. Mirrors Bookshelves::REACH.
 */
export function tilesBeside(
    piece: FurniturePiece,
    grid: Grid,
    from: Tile,
): Tile[] {
    const footprint = placedFootprint(piece);
    const tiles: Tile[] = [];

    for (let y = footprint.y - 1; y <= footprint.y + footprint.deep; y++) {
        for (let x = footprint.x - 1; x <= footprint.x + footprint.wide; x++) {
            if (
                isWalkable(grid, x, y) &&
                !covers(footprint, [x, y]) &&
                distanceTo(footprint, [x, y]) <= 1
            ) {
                tiles.push([x, y]);
            }
        }
    }

    const steps = ([x, y]: Tile) =>
        Math.max(Math.abs(x - from[0]), Math.abs(y - from[1]));

    return tiles.sort((a, b) => steps(a) - steps(b));
}

/** A home's piece as it is drawn: playing its item's animations while it is switched on. */
export function asDrawn(placed: PlacedFurniture): FurniturePiece {
    return placed.on && switches(placed)
        ? { ...placed, animations: placed.item.furniture?.animations }
        : placed;
}

/**
 * The piece standing on a tile, if any: the one on top when a piece stands on a rug.
 */
export function pieceAt(
    placed: PlacedFurniture[],
    tile: [number, number],
): PlacedFurniture | null {
    const here = placed.filter((piece) => covers(placedFootprint(piece), tile));

    return here.find(blocksWalking) ?? here[0] ?? null;
}

/**
 * Which facing's image to draw a piece from, and whether to mirror it.
 *
 * Each item carries its art by facing (`item.furniture.art`): uploaded in the
 * admin catalog, or built from resources/art/items/{slug}/{facing}.png
 * (resources/maps/README.md, "Art"). A facing without its own image mirrors
 * its mirror image: flipping an isometric picture left to right turns lower
 * left into lower right and upper left into upper right, so facing r mirrors
 * facing 3 − r. Failing that it borrows the opposite facing, the same shape
 * seen from the wrong side, or mirrors that.
 *
 * A stand-in must stand on the same tiles. A plain block always does; a
 * shaped one (an L, say) only when the borrowed image, flipped if it is,
 * covers the same tiles as the piece as it is turned, so most shapes need
 * their own image for most facings. Null when nothing fits, and a
 * placeholder is drawn instead.
 */
export function facingArt(
    rotation: number,
    has: (facing: number) => boolean,
    size: FurnitureSize = { wide: 1, deep: 1 },
): { facing: number; mirrored: boolean } | null {
    const lies = (facing: number) => footprintOf(size, 0, 0, facing);
    const opposite = (rotation + 2) % FACINGS;
    const tries: Array<[number, boolean]> = [
        [rotation, false],
        [3 - rotation, true],
        [opposite, false],
        [3 - opposite, true],
    ];

    for (const [facing, flipped] of tries) {
        const drawn = flipped ? mirrored(lies(facing)) : lies(facing);

        if (has(facing) && sameShape(drawn, lies(rotation))) {
            return { facing, mirrored: flipped };
        }
    }

    return null;
}
