# Room pieces: furniture that belongs to a room

Room pieces are things that are part of a room, such as the Coin
Laundry's washers, dryers and folding tables. Nobody buys or moves them. You
draw them, then arrange the room in **Tiled**, the map editor. Furniture
that residents buy for their homes works differently: see
[furniture.md](furniture.md). Drawing something else? See
[all the art guides](README.md).

## 1. Agree the name and size

Each piece has a **name**, lowercase with hyphens (`washer`,
`folding-table`), and covers a block of floor tiles, **wide** × **deep**,
like furniture. Tell Luigi both. They go on the list of room pieces, and a
name can't change after that.

## 2. Draw it on a furniture template

Room pieces use the furniture templates and the same rules: pick the
template for its size from [`templates/furniture/`](templates/furniture/),
draw on the diamond, light from the top left, half size, nothing below the
diamond. [Furniture steps 1 to 3](furniture.md#1-pick-the-template) explain
each one.

Facings work the same way too: one picture per facing, 0 to 3. The game
mirrors a facing you didn't draw, so:

- **Draw `0`**, the front. Every piece needs it.
- Draw **`1`**, the back, if anyone will see the piece from behind.
- Draw **`3`** as well if mirroring would look wrong: writing such as
  "WASH", a coin slot on one side, a dial in one corner.

A piece that stands against a wall usually needs only `0`.

## 2b. Moving parts: a washer that's on

A piece can be placed **off** or **on** in the room. Off, it shows its
picture. On, it plays an animation over the picture, such as clothes
tumbling in a washer's porthole. Draw only what moves; the machine itself
stays as you drew it in `0.png`.

1. In Aseprite, keep the machine (your off-state `0.png`) on a locked layer
   underneath, so you can trace over it.
2. On a new layer, draw **only the moving part** in each frame, such as the
   clothes inside the porthole. Leave everything else clear. Use **2 or more
   frames**, all the same size as `0.png`.
3. Make frame 1 look good on its own: players who turn off motion on their
   device see only that frame.
4. Hide the machine layer, then **File → Export Sprite Sheet**: horizontal
   strip, trim off, no padding, 100%. Four frames of a 32 × 40 washer make
   one PNG 128 × 40.
5. Name it after the facing it plays over, with `-on`: `washer/0-on.png`.
   The mirrored facing (`3`) gets the animation mirrored for free, the same
   way it gets the picture. Only draw `3-on.png` if you drew `3.png`.
6. Tell Luigi **how long each frame shows**, in milliseconds: one time for
   every frame ("140 ms"), or one per frame ("600, 80, 80, 80").

## 3. Export

- **PNG** with a **transparent background**, at **100%**, no smoothing.
  Hide the template first.
- Name each file by its facing, in a folder named after the piece:
  `washer/0.png`, `washer/1.png`, and `washer/0-on.png` for its animation.

## 4. Send it to Luigi

Room pieces ship with the game's code, like buildings. Send the folder. The
build checks every picture against the piece's size and tells you if
something's off (see the table below). Once it's in, the piece appears in
Tiled.

## 5. Arrange the room in Tiled

1. Open the room's map (`laundromat.json` for the Coin Laundry) in Tiled,
   from the zip of maps Luigi sends you. New to Tiled? Start with
   [maps-in-tiled.md](maps-in-tiled.md).
2. In the **Layers** panel, click the **`props`** layer.
3. Turn on **View → Snapping → Snap to Grid**, so pieces line up with the
   tiles.
4. In the **Tilesets** panel, open the **`props`** tab. Each piece is there
   four times, once per facing; a piece that switches on is there four more
   times, switched on (its preview shows the first frame of the animation).
   Click the one you want, then click the floor. The piece stands with its
   front corner where you click. To switch a piece on or off, delete it and
   place the other one.
5. To turn a piece, delete it and place another facing. Don't use Tiled's
   flip or rotate buttons: the game refuses flipped pieces.
6. Save, and send the map file to Luigi.

Pieces can't stand on a door, on the spot where players arrive, or on top
of each other, and they must leave a way to walk from the door to every
part of the floor.

## If it's refused

| The message says…                                         | What it means and how to fix it                                                                         |
| --------------------------------------------------------- | ------------------------------------------------------------------------------------------------------- |
| "is for a room piece props/props.json doesn't list"       | The folder's name isn't on the list yet. Ask Luigi to add it, with its size.                            |
| "has pixels below its footprint diamond"                  | The picture hangs below the diamond, or was drawn for the other facing. Use that facing's own template. |
| "is 64×46; it must be 48 wide"                            | Wrong canvas size for the piece. Start from the right template.                                         |
| "is already scaled up"                                    | Exported at 200% or more. Export at 100%.                                                               |
| "stands on a door" / "stands on arrival …"                | A piece in Tiled covers a door or the spot players arrive on. Move it.                                  |
| "overlaps the …"                                          | Two pieces share a tile. Move one.                                                                      |
| "wall part of the floor off from the door"                | The pieces close off part of the room. Leave a gap to walk through.                                     |
| "is flipped or rotated"                                   | Tiled's flip or rotate was used. Delete the piece and place the facing you want instead.                |
| "needs its frame times"                                   | Luigi needs the frame times for your `-on` animation.                                                   |
| "its frames must be 32 × 40, like …"                      | The animation's frames aren't the same size as the picture. Each frame is the whole `0.png` canvas.     |
| "is drawn over art/props/washer/1.png, which isn't there" | An animation for a facing you didn't draw. Name it after a facing you drew, usually `0-on.png`.         |
| "half-transparent pixel"                                  | A soft brush or faded glow in the animation. Use solid pixels.                                          |

---

_For developers: the rules are in "Room pieces" in
[`resources/maps/README.md`](../../resources/maps/README.md#room-pieces),
which wins if the two disagree. Her art goes in
`resources/art/props/{slug}/{facing}.png`, with the piece in
`resources/art/props/props.json` (with `"frame_ms"` for a piece that
switches on) and an entry in
`resources/art/provenance.json`; run `php artisan hushfield:build-assets` to
check it and refresh the props tileset in Tiled._
