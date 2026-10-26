# Maps

Each room is a Tiled isometric map (64×32 tiles) exported as JSON, imported
with `php artisan hushfield:import-map`. `App\Domain\World\TiledMapParser`
lists the layers and properties it reads; this file covers doors and buildings.

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
  middle of the door back, never on the door itself.

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

## Building art

Until a building has art it is drawn as a placeholder block, tinted by its
slug. To replace it, drop a PNG into `public/assets/v{version}/buildings/` (the
current asset version, `HUSHFIELD_ASSET_VERSION`, now 2) named by
the slug (`library.png`); the asset manifest picks it up.

- **Canvas width**: (wide + deep) × 32 px. A 7 × 5 building is 384 px wide.
- **Footprint**: the footprint diamond sits at the bottom of the canvas,
  (wide + deep) × 16 px tall. Its bottom corner touches the bottom edge at
  x = wide × 32; its left corner is on the left edge, wide × 16 px above the
  bottom; its right corner is on the right edge, deep × 16 px above the
  bottom. Nothing may hang below or beside that diamond.
- **Height**: as tall as the building needs, above the footprint.
- **Light** from the top left: the south-west face is lit, the south-east face
  is in shade.
- **Pixels**: 1:1 with the game's 64×32 tiles, no scaling, no smoothing.
- **Format**: PNG with a transparent background.
- **Door**: drawn on the face and across the tiles the map's `door` exit covers.

Deliver one whole image per building. The game slices it into vertical
strips and sorts each strip on its own, so players walking behind a long
building are hidden and players in front are drawn over it.
