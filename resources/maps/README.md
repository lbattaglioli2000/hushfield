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

The artist's source files live in `resources/art/`, one image per file,
named by slug. `php artisan hushfield:build-assets` checks them, packs and
multiplies them up to game size, names each output by its content hash and
writes the index the asset manifest (`/assets/manifest.json`) is served
from. It runs in `npm run build` (so on every Laravel Cloud build and in CI),
at the start of `composer dev`, and on demand if a release starts without
its build. Built files are never committed. Anything without art is drawn as
a placeholder, tinted by its slug. Every source needs an entry in
`resources/art/provenance.json` (who drew it, when it arrived), and none may
be over 1 MB. The plan behind all this is `docs/assets-plan.md`.

### Ground tiles

One folder per tile type, `resources/art/tiles/{type}/`, with one file per
slab height. Each is drawn at half size on a 32 × 32 canvas (the build
doubles it to 64 × 64), standing on the bottom of the canvas:

```
resources/art/tiles/
  tiles.json        the slot registry, below
  grass/full.png    a full cube
  stone/half.png    a half slab
```

- **`full.png`**, a full cube: the top face is a 32 × 16 diamond on rows 0
  to 15, its top corner on row 0; the cube's sides stand 16 pixels tall at
  the canvas's left and right edges (rows 8 to 24), and its bottom corner is
  on row 31. The ground is drawn from full cubes: their top faces lie on the
  grid.
- **`half.png`**, a half slab: the same canvas, top face and bottom edge, with
  sides half as tall, 8 pixels (rows 16 to 24), so the top face sits 8 pixels
  lower: its top corner on row 8.
- **The build checks** that nothing hangs below the bottom edge, that the
  sides reach it, and that the sides are the right height for the file's
  name. Grass tufts and the like may poke above the top face.
- **A type with only a half slab** has it packed into its full slot as
  well, bottom-aligned, so it reads sunken; the build says so each time.
  Drawing its `full.png` replaces it.

`resources/art/tiles/tiles.json` lists every tile, by type and height, in
slot order, and slot n is GID n + 1 in every map:

```json
["grass/full", "sand/full", "stone/full", "water/full", "hedge/full", "wood/full",
 "asphalt/full", "asphalt-lines/full", "bricks/full", "stone/half"]
```

- **Add a tile or a height**: put it on the end of the list, such as
  `"stone/half"`, never in the middle, so no map's tiles move. Until it has
  art it is a tinted placeholder block of its height.
- **Change a tile's art**: replace its file under the same name. The slot
  and the maps stay as they are.
- **Retire a tile**: replace its entry with `null`. `hushfield:import-map`
  then refuses any map that still draws it.

Half slabs get their own slots in the sheet, but the game has no elevation
yet: avatars don't step up or down, so maps don't use them. The folder leaves
room for more files later (another version of a height, such as
`full-2.png`, or edge pieces); the build accepts only `full.png`,
`half.png` and numbered versions of them (`full-2.png`) for now.

The build writes the `placeholder` tileset into every map in this folder
(the sheet's size and each tile's `slug` property), and a `ground.png`
preview beside them so Tiled shows the real tiles. Run it before opening a
map in Tiled, and commit the maps it changes.

### Buildings, furniture and walls

Like tiles, these are drawn at **half size**, in art pixels: one art pixel is
2 × 2 game pixels, so their pixels match the ground's. The build doubles
them. Each has a folder per slug:

```
resources/art/
  buildings/{slug}.png              one whole image per building, named as in the map
  items/{slug}/0.png … 3.png        a furniture item's facings, by item slug
  walls/{style}/left.png            a wall style's panels: left.png, right.png,
  walls/{style}/right.png           and optionally left-door.png, right-door.png
```

A facing without its own furniture image mirrors its mirror image (facing r
mirrors 3 − r), or borrows the opposite facing; see `facingArt` in
`resources/js/game/shared/furniture.ts`. A stand-in must stand on the same
tiles, which a plain block always does but a shaped piece mostly does not:
flipping a picture left to right swaps x and y, so a mirrored image is used
only when the borrowed facing's shape, flipped, is the shape of the facing
it stands in for (a U-shaped booth, symmetric left to right, mirrors facing
0 for facing 3), and an opposite facing only when the shape looks the same
from behind. Otherwise the placeholder is drawn. A Corner Sofa never
mirrors: give it all four facings. The admin item list shows each item's art
as none, partial or complete.

Buildings and furniture share one canvas rule, in art pixels, for the
footprint as it lies (a 2 × 1 love seat is 48 across at every facing):

- **Canvas width**: (wide + deep) × 16. A 1 × 1 stool is 32 wide, a 2 × 1
  love seat 48, a 7 × 5 building 192.
- **Footprint**: the footprint diamond sits at the bottom of the canvas,
  (wide + deep) × 8 tall. Its bottom corner touches the bottom edge at
  x = wide × 16; its left corner is on the left edge, wide × 8 above the
  bottom; its right corner is on the right edge, deep × 8 above the bottom.
  Its edges climb 1 pixel for every 2 across. Nothing may hang below that
  diamond.
- **Height**: as tall as it needs, above the footprint.
- **Light** from the top left: the south-west face is lit, the south-east face
  is in shade.
- **Pixels**: export at 100%, no scaling, no smoothing. **Format**: PNG with a
  transparent background.
- **Front**: a building's door is drawn on its face, across the tiles its
  `door` exit covers. Furniture faces the lower-left edge at facing 0.

**The build checks**: a building's width against its footprint in the maps
(one name, one size, wherever it stands), and that nothing hangs below the
diamond. It can't read the catalog, so for furniture it checks the width is
a whole number of tiles (a multiple of 16) and that the art stands on some
footprint diamond; if the width doesn't match the item, the game draws the
placeholder and the item list says partial. It refuses any file exported at
200%.

Deliver one whole image per building or facing. The game slices it into
vertical strips half a tile wide and sorts each strip on its own, so players
walking behind a long building are hidden and players in front are drawn over
it. Rugs lie flat under everything.

A shaped piece is drawn over its whole block, empty tiles included; leave
them transparent. Where a strip runs over an empty tile with the piece both
behind and in front of it (the inner corner of an L, seen from the side),
the strip is cut into slices 4 art pixels wide, and each slice is cut across where
the front part's art can begin: its back edge, raised by the art's height
above the footprint. The part above sorts as the back arm and the part below
as the front arm, so someone standing in the corner is drawn over one and
under the other. Anything of the back arm below that line sorts with the
front arm, so a sliver of the back arm's foot, a pixel or two, can be drawn
over someone in the corner.

### Wall panels

A wall style is a folder, `resources/art/walls/{style}/`, of panels repeated
along the walls: `left.png` for the top-left wall (it runs down the map's
left side and faces the viewer's lower right) and `right.png` for the
top-right wall (facing the lower left). In art pixels:

- **Canvas**: 16 wide (one tile edge across) and 56 tall: 48 of wall, about
  three tiles, plus the 8 the floor's edge climbs across it.
- **Floor edge**: the face stands on the floor's edge, which runs corner to
  corner across the bottom of the canvas. On `left` it runs from the
  bottom-left corner up to 8 above the bottom-right corner; on `right`,
  from 8 above the bottom-left corner down to the bottom-right corner.
  Nothing may hang below it; the build checks. Panels butt edge to edge, so
  the art should tile along the wall.
- **Height**: the top of the face is 48 above the floor edge at every point;
  a taller canvas is fine, its bottom edge is what lines up.
- **Light** from the top left, as for buildings: `right` faces the light and
  is lit, `left` is in shade.
- **Pixels**: export at 100%, no scaling, no smoothing. **Format**: PNG with a
  transparent background.
- **Doorway** (optional): `left-door.png`, `right-door.png`, the same panel
  with an opening from the floor up, through which the door's floor tile
  shows. Without it, a style with art leaves a gap where a door is.
- **Window** (later): `left-window.png`, `right-window.png` are reserved for a
  panel with a window; the build takes them, but the game does not draw them
  yet.
