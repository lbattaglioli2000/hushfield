# Maps in Tiled, explained simply

How a room you arrange in Tiled on your own computer ends up in the game.
No coding needed on your side.

## The short version

1. **Luigi sends you a zip** of the maps.
2. **You unzip it and open a map in Tiled**, such as `laundromat.json`.
3. **You arrange the room** (where the washers stand) and save.
4. **You send back that one `.json` file.**
5. **Luigi checks it, adds it to the game and ships it.** Next time the game
   updates, everyone sees your room.

That's it. The rest of this page explains each step.

## What a map is

Think of a room as a board game board. The map file (`laundromat.json`) is
a written description of that board: how big it is, what floor goes on
each square, where the door is, and where each washer stands. Tiled is the
program that lets you see and change that board instead of reading the
text.

A map is made of **layers**, like sheets of tracing paper stacked on top of
each other:

| Layer                         | What's on it                                       | Who changes it              |
| ----------------------------- | -------------------------------------------------- | --------------------------- |
| `props`                       | The room's own furniture: washers, dryers, benches | **You**                     |
| `ground`                      | What the floor looks like (wood, tiles)            | You, if you only repaint it |
| `walkable`                    | Which squares people can walk on (hidden)          | Luigi                       |
| `spawns`, `exits`, `arrivals` | Where people appear, the door, where they arrive   | Luigi                       |

The pictures you see in Tiled (the floor tiles and the washers) aren't
inside the map file. They're separate image files that sit **next to** it in
the same folder. That's why you get a whole folder in a zip, and why you
keep it together.

## 1. Get the maps

Ask Luigi for the maps. He presses **Pack maps for Tiled** in the admin
panel (World → Live rooms), gets a download link a moment later, and
sends it to you. The link works for a day.

Unzip it. You get a folder called `hushfield-maps` with every room's
`.json` file, a `ground.png`, and a `props` folder of furniture pictures.
**Keep everything in that folder together.** If you move just the `.json`
somewhere else, Tiled can't find the pictures and shows empty squares.

**Get a fresh zip every time you start on a room.** The maps change as the
game grows. If you work on an old copy, sending it back would undo whatever
changed since.

## 2. Install Tiled

Download **Tiled 1.11.2** from [mapeditor.org](https://www.mapeditor.org/)
(it's free; the download page lets you pick a version). Other versions
mostly work, but 1.11.2 is what our maps were made with.

## 3. Arrange the room

Open the room's `.json` file in Tiled (_File → Open_). For the Coin Laundry
that's `laundromat.json`. Then follow
[room-pieces.md, step 5](room-pieces.md#5-arrange-the-room-in-tiled):
pick the `props` layer, pick a washer from the `props` tileset at the
bottom, and click where it should stand.

A few rules the game checks for you:

- Washers can't stand on the door, or on the spot just inside it where
  people arrive.
- Two pieces can't share a square.
- People must still be able to walk from the door to every part of the
  floor.
- Don't use Tiled's flip or rotate buttons. To turn a washer, delete it
  and place the version facing the way you want.
- To show a washer running, place its "on" version. Not running? Place
  the plain one.

Want a new kind of piece that isn't in the tileset yet, such as a dryer?
Draw it first ([room-pieces.md](room-pieces.md)) and send it to Luigi.
It shows up in the tileset in your next zip.

Want to change the shape or size of the room, or move the door? Ask Luigi.
Those touch the hidden layers.

## 4. Send it back

Save in Tiled (_File → Save_). Send Luigi **just the room's `.json` file**,
such as `laundromat.json`. He already has the pictures.

If something's wrong, Luigi gets a plain message saying what, such as
"stands on a door". The table at the end of
[room-pieces.md](room-pieces.md#if-its-refused) says what each one means and
how to fix it.

## 5. What happens next (Luigi's part)

You don't have to do any of this; it's here so you know what happens to
your file.

1. **Put it in place.** Luigi copies your file over the old one in the
   game's code (`resources/maps/laundromat.json`).
2. **Check it.** Two commands: one tidies the file (Tiled sometimes
   renumbers things when it saves), and one reads the room the way the
   game will. That one catches a washer on the door or a blocked path.
3. **Look at it.** He walks around the room in the game on his own
   computer.
4. **Save it to the code.** In developer words: commit and push. That's
   like hitting "save" on the shared copy everyone works from.
5. **Ship it.** The next time the game updates (a "deploy"), it loads the
   new map, and everyone who walks into the Coin Laundry sees your
   arrangement.

## Words you might hear

| Word              | What it means                                                                                    |
| ----------------- | ------------------------------------------------------------------------------------------------ |
| **Map**           | One room's `.json` file.                                                                         |
| **Layer**         | One sheet of tracing paper in the map.                                                           |
| **Tileset**       | The palette of pictures you paint or place with in Tiled, shown at the bottom right.             |
| **Props**         | A room's own furniture, such as the laundry's washers. Nobody can buy or move these in the game. |
| **Build**         | Turning your drawings into the pictures the game and Tiled use. Luigi's computer does it.        |
| **Import**        | The game reading a map and checking it.                                                          |
| **Commit / push** | Saving a change into the shared code.                                                            |
| **Deploy**        | Putting the latest code live for everyone.                                                       |

---

_For developers: **Pack maps for Tiled** on the admin's Live rooms page
(admins only) queues `PackMapsForTiled` on the `map-exports` queue, a
Laravel Cloud managed queue with 1 GB, off the app's instances. It zips
`resources/maps` (every map, `ground.png` and `props/`, the pictures the
build writes and git ignores) into `tiled/hushfield-maps-{time}.zip` on the
`private` disk and sends whoever pressed it an admin notification with a
link that works for 24 hours. `php artisan hushfield:pack-maps` does the
same at once and prints the link (locally, a local folder disk has no
links, so it prints the file's path; run `php artisan
hushfield:build-assets` first). Zips are never deleted; clear out `tiled/`
now and then. When a map comes back: copy it into `resources/maps/`, run
`php artisan hushfield:build-assets` and
`php artisan hushfield:import-map {slug}`, check the room, then commit the
map. A deploy must run `hushfield:import-map` after migrating: otherwise the
server walks players by the old layout while their browsers draw the new
file (`MapController` serves the current file under the old version)._
