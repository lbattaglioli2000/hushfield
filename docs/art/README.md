# Hushfield art guides

Everything you need to draw for Hushfield and get it into the game. Start
with the guide for what you're drawing; each one walks through picking a
template, drawing, exporting and handing it over.

| Drawing…                                      | Guide                          | Templates                                                    | How it gets in     |
| --------------------------------------------- | ------------------------------ | ------------------------------------------------------------ | ------------------ |
| Ground tiles (grass, stone, floors)           | [tiles.md](tiles.md)           | [`templates/tiles/`](templates/tiles/)                       | send to Luigi      |
| Furniture                                     | [furniture.md](furniture.md)   | [`templates/furniture/`](templates/furniture/)               | upload it yourself |
| Buildings (the outside)                       | [buildings.md](buildings.md)   | [`templates/buildings/`](templates/buildings/)               | send to Luigi      |
| Moving parts of a building (a blinking light) | [animations.md](animations.md) | the building's own canvas                                    | send to Luigi      |
| Walls and wallpaper (indoors)                 | [walls.md](walls.md)           | [`templates/walls/`](templates/walls/), one folder per style | send to Luigi      |

Not ready to draw yet: **avatars and clothes**, and the **markers** under
players' feet. Their sizes aren't settled; Luigi will send a brief first.

## Rules for everything

- **Draw at half size.** The game doubles every pixel, so your pixels match
  the ground's. Templates are already at the right size.
- **Start from the template**, on a layer above it, and hide it before you
  export. Don't resize a picture to fit a template: that squashes the angles.
- **Light comes from the top left.** Faces turned to the lower left are lit;
  faces turned to the lower right are in shade.
- **Nothing hangs below the floor** the thing stands on: the diamond on a
  furniture or building template, the bottom of a tile, the floor edge of a
  wall panel.
- **Export PNG at 100%** with a transparent background and no smoothing. In
  Aseprite: _File → Export As…_, PNG, scale 100%.
- **Names** are lowercase, words joined with hyphens: `love-seat`, not
  `Love Seat.png`. Name a file like its template.
- **Changing something?** Send it again under the same name. Never add `-v2`
  or `-final`; the game keeps the history.
- **Send the `.aseprite`** beside the PNG if you like, as long as it's under
  1 MB.

## What's still plain

Anything without art is drawn as a plain tinted block. Still waiting for
art:

- **Tiles:** the hedge (on every map).
- **Buildings:** the Public Library. WHSH's beacon animation is a stand-in
  until you draw the real one ([animations.md](animations.md#example-the-whsh-beacon)).
- **Walls:** every style (wallpaper, plaster, acoustic, tiled, velvet,
  siding).
- **Furniture:** the admin item list shows each piece's art as none,
  partial or complete.

Stuck, or an upload says no? Each guide ends with a table of messages and
fixes. Otherwise send the PNG to Luigi with the message.

---

_For developers: the rules these guides follow are in "Art" in
[`resources/maps/README.md`](../../resources/maps/README.md#art), which wins
if they disagree; the plan behind the pipeline is
[`docs/assets-plan.md`](../assets-plan.md). Regenerate every template with
`php docs/art/templates/make-templates.php` after adding a building or a
wall style to a map, or a size to its `PIECES`, and update "What's still
plain" when art lands._
