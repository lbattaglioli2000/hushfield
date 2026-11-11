# Animating parts of a building

How to make a small part of a building move, such as WHSH's orange beacon
blinking or its rings turning. It builds on [drawing buildings](buildings.md),
so everything there still applies.

Animating a room piece instead, such as the Coin Laundry's washers tumbling
when they're on? That works the same way, over the piece's own picture: see
[room pieces](room-pieces.md#2b-moving-parts-a-washer-thats-on). Home
furniture and walls can't move yet.

The building stays **one still picture**. Each moving part is a **second
file**: a short strip of frames that the game plays over the building in a
loop. You never redraw the whole building for an animation.

## 1. Leave the part out of the building

Draw the building **without the moving part**, and paint **what's behind
it** instead: for the beacon, plain roof where the beacon stands. The part
lives only in its animation.

That way the frames can move it, turn it or change its shape freely. If the
part were also in the building, it would show through underneath every
frame. It also means the two files belong together: **send the building and
its animations at the same time**, or the building shows a gap where the
part should be.

## 2. Set up the animation file

- Make a **new file the same size as the building**: 128 × 176 for WHSH.
  Same width, same height.
- Put the building underneath as a **locked reference layer**, so you draw
  the frames exactly where they belong. You'll hide it before exporting.
- Add frames in Aseprite's timeline. **2 to 8 frames** is plenty for most
  things.

## 3. Draw the whole part in every frame

- Each frame shows the **whole part** as it looks at that moment. Leave
  everything else transparent.
- **Frame 1 is also the still picture.** Some players turn animations off in
  their device's settings, and they see frame 1 all the time. Make it a good
  look for the part at rest: the beacon lit, say, rather than dark.
  WHSH's beacon is the exception: it plays only while the station is on
  the air and holds frame 1 the rest of the time, so draw its frame 1 as
  the beacon switched off.
- **Solid pixels only**: no soft or half-transparent glow. Show glow as
  lighter solid pixels around the light, like the rest of the art.
- **Make it loop**: the last frame goes straight back to the first, so they
  should flow into each other.
- Same rules as the building: **half size**, light from the **top left**,
  nothing below the footprint diamond.
- **Keep it small.** The game trims each animation to the box around
  everything any frame draws, and loads only that. A light and a sign at
  opposite ends of the roof would make one huge box, so make them **two
  separate animations** (step 5).
- **The animation is always drawn in front of the building.** If something
  moves behind part of the building, like a ring passing behind the tower,
  redraw that part of the tower in the frame, over the ring.
- **Players walk in front of it and behind it** as they do the building:
  the part of the animation over each bit of the building hides and shows
  with that bit. A part sticking far out past the building's edge still
  sorts with the bit of building it's next to, so keep parts close to the
  building.

## 4. Export

- _File → Export Sprite Sheet_, with:
    - **Layout:** horizontal strip.
    - **Layers:** only the animation layer (hide the reference building).
    - **Trim:** off.
    - **Padding:** none.
    - **Scale:** 100%.
- This gives one PNG with the frames side by side. For a 4-frame beacon on
  WHSH, it is 4 × 128 = **512 × 176**.
- Name it **after the part**, in a folder named exactly like the building's
  file: `whsh/beacon.png` beside `whsh.png`. Lowercase, words joined with
  hyphens.

## 5. Send it to Luigi, with its timing

Send the PNG, and the `.aseprite` file too if you like. Tell him **how long
each frame shows**, in milliseconds:

- **One time for every frame**, such as "200 ms". At 200 ms, a 4-frame
  blink takes 0.8 seconds.
- **Or one time per frame**, to hold some longer: "800, 100, 100, 100" keeps
  the beacon dark, then flashes it quickly.

A building can have **several animations**, each its own file with its own
timing. They all start together when a player arrives, then each loops at
its own pace. To keep two in step, give them the same total length.

```
whsh.png              the building, with plain roof where the beacon stands
whsh/beacon.png       4 frames, 200 ms each
whsh/rings.png        6 frames, 120 ms each
```

## Example: the WHSH beacon

| What                | Value                                                   |
| ------------------- | ------------------------------------------------------- |
| Building            | `whsh.png`, 128 × 176, plain roof where the beacon goes |
| Animation file      | `whsh/beacon.png`, 512 × 176 (4 frames of 128 × 176)    |
| Frames              | lit → bright → lit → dim                                |
| Timing              | 200 ms a frame, one blink every 0.8 s                   |
| Drawn in each frame | the whole beacon and its glow; everything else clear    |
| Still picture       | frame 1, the beacon lit                                 |

Today's beacon in the game is a stand-in: it draws over the beacon already
in `whsh.png`. When you draw the real one, send `whsh.png` without the beacon
along with `whsh/beacon.png`.

## If it's refused

| The message says…                                           | What it means and how to fix it                                                                                                                 |
| ----------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------- |
| "its frames must be 128 × 176, like art/buildings/whsh.png" | Each frame must be exactly the building's size, side by side. Start from a copy of the building's canvas, and export with trim and padding off. |
| "is drawn over art/buildings/whsh.png, which isn't there"   | The folder's name doesn't match the building's file, or the building itself is missing. `whsh/`, not `WHSH/` or `radio-station/`.               |
| "has one frame"                                             | A part that doesn't move belongs in the building itself.                                                                                        |
| "has a half-transparent pixel at (x, y)"                    | Some pixels in that frame are partly see-through, usually a soft brush or a faded glow. Use solid pixels.                                       |
| "Frame 2 of … has pixels below its footprint diamond"       | Same as for buildings: something in that frame hangs below the diamond.                                                                         |
| "is already scaled up"                                      | It was exported at 200% or more. Export at 100%.                                                                                                |
| "has nothing drawn in it"                                   | Every frame is empty. Check the animation layer was visible when you exported.                                                                  |
| "needs its frame times"                                     | Luigi hasn't added the timing yet, or it doesn't match the number of frames. Tell him how long each frame shows.                                |

---

_For developers: the rules are in "Building animations" in
[`resources/maps/README.md`](../../resources/maps/README.md#building-animations),
which wins if the two disagree. Her strip goes in
`resources/art/buildings/{slug}/{part}.png` with an entry in
`resources/art/provenance.json`, and its frame times in
`resources/art/buildings/animations.json`._
