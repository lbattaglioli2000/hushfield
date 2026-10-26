# Maps, places and art

How to add to Hushfield: a room, its walls, a door, a fixture, a building, a
piece of furniture, and their art. Everything on a map, and every piece of furniture,
stands on a **footprint**: a block of tiles `wide` along x and `deep` along y
from its back corner. `App\Domain\World\Footprint` and
`resources/js/game/shared/footprint.ts` answer "what covers this tile" for all
of them.

## A room

Each room is a Tiled isometric map (64×32 tiles) exported as JSON to
`resources/maps/{slug}.json`, and imported with
`php artisan hushfield:import-map {slug}` (no slug imports every map). The
import validates the map, caches its walkability grid and creates or updates
the room. `App\Domain\World\TiledMapParser` is the source of truth for what
it reads:

- **`walkable`** (tile layer, CSV, required): any non-zero tile is walkable.
- **`ground`** (tile layer): what is drawn, from the `placeholder` tileset.
- **`spawns`** (object layer, required): points where players appear.
- **`exits`**, **`arrivals`**, **`fixtures`**, **`buildings`** (object layers,
  optional): see below.
- **Map properties**: `name`, `kind` (`public`, `residence` or `yard`), `capacity`,
  `frequency` (the room's station on the tuning dial, in MHz),
  `home_frequency` (a residence's homes on the dial), `town_x`, `town_y`
  (its cell on the town map; without them the room is listed under the map),
  `walls` (an indoor room's wall style) and `wall_sides` (which back edges
  it lines; see below).
  Add the room's dial label to `stations` in `lang/en/hushfield.php`.

Tiled measures objects on isometric maps in tile-height (32 px) units on both
axes, so a point at (96, 64) is tile (3, 2).

A home is a house and a yard: one instance each of the `residence` map and
the `yard` map, both belonging to the one resident and both following their
visitor setting. Furniture goes in either. A door between them works like any
other, but it keeps to the home the player is in, so a friend's back door
leads to that friend's yard. A yard has no cell on the town map and no
`frequency`; give it the house's `home_frequency`.

## Walls

An indoor room has back walls, as in Habbo: upright panels along the map's two
back edges, the top-left (the column x = 0) and the top-right (the row y = 0).
The two front edges stay open, so players can always see in. Outdoor rooms
have no walls; hedges or the map's edge close them in.

A map can also wall just one back edge, with a string property
**`wall_sides`**: `left` (the column x = 0), `right` (the row y = 0), or
`both`, the default. The other back edge stays as the map draws it, and so
does the corner (0, 0), which is under the walls only when both stand. A yard
uses this for the outside of its house: siding along one edge, with the door
back in as a doorway, and hedge along the other.

To turn walls on, give the map a string property **`walls`** naming a style,
a slug such as `plaster`. It names the style's art; until there is some, each
panel is a plain block tinted by the style's name. In use: `plaster` (the
Library), `acoustic` (WHSH), `tiled` (the Coin Laundry), `wallpaper` (every
house in Hearthmore Residences), `siding` (the back of the house, seen from
its yard, with `wall_sides` `left`).

- **Where they stand**: on the back row and column (or the one edge
  `wall_sides` names), their faces on those
  tiles' inner edges, where the floor begins, meeting behind the corner tile
  (0, 0). Paint those tiles unwalkable: the import refuses a walkable tile
  there unless a door covers it. Whatever the `ground` layer draws there is
  hidden; the walls replace it.
- **Doorways**: a door on the back row or column opens its panels as a
  doorway, with its floor showing through. Draw the door on those tiles as
  usual; nothing else is needed.
- **Only looks**: walls change nothing about walking, and the server only
  checks the property. Nothing can stand behind them, so they never fade.

Each panel is one tile, so things hung on walls later (paintings, clocks) can
name the panel they hang on.

## Doors

A door is an object on the `exits` layer, with properties `to` (the room it
leads to), `arrive` (an arrival point in that room) and `label`.

- **Shape**: a point is a one-tile door. A wide door is one rectangle, snapped
  to whole tiles, along the row or column the door opens in. It is still one
  door: one name, one glowing band, one label over its middle tile.
- **Width**: doors between outdoor rooms are 3 tiles wide, and the gap in the
  hedge or edge around them is open across the same 3 tiles, so players can
  walk in with the arrow keys without lining up first. A building has a single
  doorway: its door, and the door back out of the room inside, are one tile.
- **Arrival**: put the arrival point on the other side one tile in front of the
  middle of the door back, never on the door itself. Arrivals are named points
  on the `arrivals` layer.

## Fixtures

A fixture is a named point on the `fixtures` layer, with a `kind` and an
optional `label`. Walking onto it uses it: the client emits `use-fixture` on
the event bus, and the panel for that kind opens. It may not sit on a door.

| kind      | what it is      | where the server lets players use it       |
| --------- | --------------- | ------------------------------------------ |
| `shop`    | a shop counter  | anywhere in the room                       |
| `fishing` | a fishing spot  | on the spot or a step from it              |

To add a kind (a minigame, say):

1. Server: add a case to `App\Domain\World\FixtureKind` with its `reach()`,
   and guard the actions it allows with
   `FixtureReach::ensureWithin($user, FixtureKind::YourKind)`. Its refusals
   read `{kind}_elsewhere` and `{kind}_too_far` in `lang/en/hushfield.php`.
   A kill switch is a `WorldFlag` case, a Pennant feature class, and
   `WorldFlag::YourKind->ensureActive()` in the same actions.
2. Client: add the kind to `FixtureKind` in
   `resources/js/game/protocol/types.ts`, and open its panel with
   `useFixture(bus, 'your-kind', ...)` from `resources/js/hooks/use-game-bus.ts`.
   Player copy goes in `lang/en/hushfield.php` and, for client keys, in both
   `PlayController::COPY_KEYS` and `resources/js/game/copy.ts`.

## Buildings

A building is a rectangle object on an object layer named `buildings`:

- **Name**: the building's slug, such as `library`. It names its art.
- **Position and size**: snapped to whole tiles. Its width is how many tiles
  it runs along x (wide), its height how many along y (deep). Tiled measures
  both in tile-height (32 px) units on isometric maps, so a building 7 wide
  and 5 deep is 224 × 160.
- **`door`** (string): the name of an exit on the `exits` layer. The exit must
  lie along the building's front edge: its last column (the south-east face)
  or its last row (the south-west face). Those are the two faces players see.
- **`height`** (int): how tall the walls stand, in pixels. Only used for the
  placeholder block; real art brings its own height.
- **`label`** (string, optional): the name shown when hovering the building.
  Defaults to the door's label.

Every tile of the footprint is blocked except the door's, whatever the
`walkable` layer says. The footprint may not cover a spawn, an arrival, a
fixture, another exit or another building, and may not run off the map.

The room inside should have an exit back to this map whose `arrive` point is
the tile just outside the middle of the door (for a door of even width, the
later of its two middle tiles), so players come out where they went in.

## Furniture

A furniture item is a catalog `Item` with slot `furniture`, added in the
admin catalog or `database/seeders/ItemSeeder.php`. It says how many tiles it
covers before any turn (`wide` along x, `deep` along y), how tall its
placeholder block stands (`height`, in pixels), and whether it lies flat on
the floor like a rug (`floor`). Players walk around pieces and over rugs; one
piece may stand on a rug.

Pieces turn in quarter turns (`rotation`, 0–3). At 0 a piece faces the
viewer's lower left (+y); each turn is a quarter turn clockwise seen from
above, so 1 faces the upper left (−x), 2 the upper right (−y), 3 the lower
right (+x). An odd turn swaps how wide and how deep it is; the back corner
stays put.

A piece need not fill its block. Its **shape** (`shape` on the item, the
Shape grid in the admin catalog) says which of its `wide × deep` tiles it
covers: one row per tile along y, back row first, one character per tile
along x, `#` covered and `.` empty. The Corner Sofa, 2 × 2, is `##` over `#.`.
No shape is a plain block, as every piece was before shapes. Empty tiles are
not part of the piece at all: players walk on them, other pieces stand on
them (a side table tucks into an L), and they must stay reachable from the
door. A shape must be one piece, joined along tile edges, and touch all four
sides of its block; blocks go up to 8 × 8. The shape turns with the piece: a
quarter turn clockwise seen from above takes `##` over `#.` to `##` over `.#`.
`Footprint` holds the shape as its `mask`, on both sides.

## Art

Art is found by name, with no code change: the asset manifest lists whatever
PNGs are in `public/assets/v{version}/` (the current asset version,
`HUSHFIELD_ASSET_VERSION`, now 2). Anything without art is drawn as a
placeholder block, tinted by its slug.

| what      | folder        | file name                                   |
| --------- | ------------- | ------------------------------------------- |
| building  | `buildings/`  | `{slug}.png`, the building's name           |
| furniture | `furniture/`  | `{slug}-{rotation}.png`, or `{slug}.png` for facing 0 |
| walls     | `walls/`      | `{style}-left.png` and `{style}-right.png`; optionally `{style}-left-door.png`, `{style}-right-door.png` |

A facing without its own furniture image mirrors its mirror image (facing r
mirrors 3 − r), or borrows the opposite facing; see `facingArt` in
`resources/js/game/shared/furniture.ts`. A stand-in must stand on the same
tiles, which a plain block always does but a shaped piece mostly does not:
flipping a picture left to right swaps x and y, so a mirrored image is used
only when the borrowed facing's shape, flipped, is the shape of the facing
it stands in for (a U-shaped booth, symmetric left to right, mirrors facing
0 for facing 3), and an opposite facing only when the shape looks the same
from behind. Otherwise the placeholder is drawn. A Corner Sofa never
mirrors: give it all four facings.

Buildings and furniture share one canvas rule, for the footprint as it lies
(a 2 × 1 love seat is 96 px across at every facing):

- **Canvas width**: (wide + deep) × 32 px. A 7 × 5 building is 384 px wide.
- **Footprint**: the footprint diamond sits at the bottom of the canvas,
  (wide + deep) × 16 px tall. Its bottom corner touches the bottom edge at
  x = wide × 32; its left corner is on the left edge, wide × 16 px above the
  bottom; its right corner is on the right edge, deep × 16 px above the
  bottom. Nothing may hang below or beside that diamond.
- **Height**: as tall as it needs, above the footprint.
- **Light** from the top left: the south-west face is lit, the south-east face
  is in shade.
- **Pixels**: 1:1 with the game's 64×32 tiles, no scaling, no smoothing.
- **Format**: PNG with a transparent background.
- **Front**: a building's door is drawn on its face, across the tiles its
  `door` exit covers. Furniture faces the lower-left edge at facing 0.

Deliver one whole image per building or facing. The game slices it into
vertical strips half a tile wide and sorts each strip on its own, so players
walking behind a long building are hidden and players in front are drawn over
it. Rugs lie flat under everything.

A shaped piece is drawn over its whole block, empty tiles included; leave
them transparent. Where a strip runs over an empty tile with the piece both
behind and in front of it (the inner corner of an L, seen from the side),
the strip is cut into slices 8 px wide, and each slice is cut across where
the front part's art can begin: its back edge, raised by the art's height
above the footprint. The part above sorts as the back arm and the part below
as the front arm, so someone standing in the corner is drawn over one and
under the other. Anything of the back arm below that line sorts with the
front arm, so a sliver of the back arm's foot, a pixel or two, can be drawn
over someone in the corner.

### Wall panels

A wall style is two images, one panel each, repeated along the walls: `left`
for the top-left wall (it runs down the map's left side and faces the
viewer's lower right) and `right` for the top-right wall (facing the lower
left).

- **Canvas**: 32 px wide (one tile edge across) and 112 px tall: about three
  tiles of wall, 96 px, plus the 16 px the floor's edge climbs across it.
- **Floor edge**: the face stands on the floor's edge, which runs corner to
  corner across the bottom of the canvas. On `left` it runs from the
  bottom-left corner up to 16 px above the bottom-right corner; on `right`,
  from 16 px above the bottom-left corner down to the bottom-right corner.
  Nothing may hang below it. Panels butt edge to edge, so the art should tile
  along the wall.
- **Height**: the top of the face is 96 px above the floor edge at every
  point; a taller canvas is fine, its bottom edge is what lines up.
- **Light** from the top left, as for buildings: `right` faces the light and
  is lit, `left` is in shade.
- **Pixels**: 1:1, no scaling, no smoothing. **Format**: PNG with a
  transparent background.
- **Doorway** (optional): `{style}-{side}-door.png`, the same panel with an
  opening from the floor up, through which the door's floor tile shows.
  Without it, a style with art leaves a gap where a door is.
- **Window** (later): `{style}-{side}-window.png` is reserved for a panel
  with a window; the game does not draw it yet.
