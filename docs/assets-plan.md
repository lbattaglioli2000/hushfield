# Game assets plan

Where Hushfield's art and audio live, how they're named, built, versioned and
delivered, and how we get there from today. Written against `github/main` at
`27a65a2`. This is a plan: nothing in it is built yet.

**In one paragraph:** Keep the artist's source files in the repo under
`resources/art/` (and `resources/audio/`), one file per tile, building or
frame, named by slug. Track every file's origin in one `provenance.json`. An
artisan command builds what ships: it packs the tile sheet in a fixed slot
order, gives every output file a content hash in its name, and writes the
index the manifest is served from. Built files aren't committed. Art that a
map or the code refers to ships with the code. Art that a catalog row refers
to (furniture and wearables) is uploaded through Filament to a bucket, so a
new item never needs a deploy. Production serves everything from one public
bucket with immutable caching. Locally, the app serves the files itself.

---

## 1. Where things stand today

Checked against the code, not the brief.

| What                 | Where                                                                                              | Notes                                                                                                                                                                                                                            |
| -------------------- | -------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Ground tiles         | `public/assets/v2/tiles.png`, 384×64                                                               | 6 slots, each a 32×32 block doubled to 64×64 (checked: it's an exact nearest-neighbour 2×, so it splits back losslessly). Slot `n` is Tiled GID `n+1`.                                                                           |
| Other images         | `public/assets/v2/resident.png` (28×44), `marker.png`, `hover.png` (64×32)                         | Listed by name in `config/hushfield.php` → `assets.images`.                                                                                                                                                                      |
| Buildings, furniture | `buildings/{slug}.png`, `furniture/{slug}[-{rotation}].png`                                        | **Neither folder exists yet.** The convention is only in code and the README.                                                                                                                                                    |
| Manifest             | `AssetManifestController` at `/assets/manifest.json`, `no-cache`                                   | URLs are `{HUSHFIELD_ASSET_URL or /assets}/v{HUSHFIELD_ASSET_VERSION}/…`. Building and furniture entries come from **globbing `public/assets/v{n}/` on the app server**, so a CDN-only file never shows up.                      |
| Client loading       | `RoomScene.preload()`                                                                              | Loads the tile sheet, the avatar, marker, hover and **every building and furniture image in the manifest, at boot**. Map JSON is already lazy, per room, and served immutable by `MapController`.                                |
| Maps                 | `resources/maps/*.json`                                                                            | Each embeds a `placeholder` tileset that is **out of date**: it says `imageheight: 32, tileheight: 32`, but the sheet is 64 tall. It works only because `IsoMap` passes the real sizes to `addTilesetImage`.                     |
| Versioning           | Folder rename `v1` → `v2` and a new default for `HUSHFIELD_ASSET_VERSION`                          | Every file's URL changes on every bump, and a server whose env still pins `1` breaks.                                                                                                                                            |
| S3                   | `aws/aws-sdk-php` and `league/flysystem-aws-s3-v3` installed; default `s3` disk                    | Nothing uses them. Production is Laravel Cloud, which offers R2-backed public and private buckets.                                                                                                                               |
| CORS                 | No `crossOrigin` anywhere in `resources/js`                                                        | **This breaks a cross-origin CDN.** `BuildingLayer` reads pixel alpha and `FurnitureLayer` draws flipped copies onto a canvas, and both fail on images loaded without CORS.                                                      |
| Tile sheet assembly  | By hand: crop, double, paste with Pillow or ImageMagick                                            | Done by hand three times so far.                                                                                                                                                                                                 |
| Provenance           | **Nowhere.**                                                                                       | Slots 0 (grass) and 2 (stone) are our artist's. Slots 1 (sand, `tile_000`), 3 (water, `tile_110`), 4 (hedge, `tile_029`) and 5 (wood, `tile_025`) come from a pack Luigi found online. Its name and licence were never recorded. |
| Item art             | `Item.slug` (can't change once created), slots `head face body feet hand pin background furniture` | Art is found by slug. There is no upload.                                                                                                                                                                                        |
| Audio                | None                                                                                               | Radio static is synthesised in `use-radio-sound.ts`.                                                                                                                                                                             |

Two latent bugs the plan fixes along the way:

- **A furniture name can mean two things.** `chair-2.png` could be item `chair-2` at facing 0, or item `chair` at facing 2. This is fixed by giving each item its own folder (§3).
- **Pixel density is inconsistent.** Tiles are 32-px art shown at 2×. The README says buildings and furniture are drawn 1:1 at game resolution, so their pixels would be half the size of the ground's. This needs a decision (§9, decision A).

## 2. Size expectations

Small pixel-art PNGs keep everything that ships small for years:

| Kind                                             | Per file       | Plausible count | Total                  |
| ------------------------------------------------ | -------------- | --------------- | ---------------------- |
| Ground tile (32×32 source)                       | 0.3–1 KB       | 100–300         | < 0.3 MB               |
| Building (≈ 400×400)                             | 10–40 KB       | 30–60           | ≈ 2 MB                 |
| Furniture facing                                 | 1–6 KB ×4      | 300–1000 items  | ≈ 6–20 MB (bucket)     |
| Wearable (front + back strips of 96×144 frames)  | 5–20 KB        | 500–2000 items  | ≈ 10–40 MB (bucket)    |
| `.aseprite` sources                              | ≈ 1–3× the PNG |                 | doubles the repo share |
| Room music (shipped, Opus ≈ 96 kbps, 2-min loop) | ≈ 1.5 MB       | 10–30           | 15–45 MB               |
| Masters (WAV, PSD, `.blend`)                     | 5–200 MB       |                 | **not in Git**         |

Git copes with everything except the masters. Item art goes to the bucket
anyway (§6).

## 3. Recommended layout

### Sources, committed

```
resources/
  art/
    provenance.json            # every file → author, source, licence (§8)
    tiles/
      tiles.json               # slot registry: ordered slugs, append-only (§4)
      grass.png                # 32×32 block, one file per tile slug
      grass.aseprite           # optional working file, same name
      grass-flowers.png        # a variant is just another slug
      grass-edge-ne.png        # so is an edge or transition piece
      stone.png
      …
    buildings/
      library.png              # one whole image per building slug (canvas rule in maps README)
      night-market.png
    walls/                     # provisional, nothing uses walls yet
      {slug}/left.png          # wall running along x, one tile edge long
      {slug}/right.png         # wall running along y
      {slug}/corner.png
    avatars/
      base/front.png           # strip: idle + 4 walk frames, each 96×144, fixed anchor
      base/back.png
    ui/
      marker.png
      hover.png
      resident-placeholder.png # today's resident.png until avatars land
      radio/…                  # art drawn by React goes through Vite, not the manifest
  audio/
    music/{room-slug}.opus     # shipped encodes only; masters stay outside Git
    music/{room-slug}.m4a      # AAC fallback, if Safari support needs it
    sfx/{slug}.opus            # e.g. recorded static, if it replaces the synth
database/seeders/art/          # sample item art for local dev, handover layout (§6)
  items/{slug}/…
```

### Item art, in the bucket (uploaded through Filament, §6)

```
items/{slug}/0.{hash}.png      # furniture facing 0–3; missing facings mirror or borrow (facingArt)
items/{slug}/1.{hash}.png
items/{slug}/front.{hash}.png  # wearable: strip of 96×144 frames
items/{slug}/back.{hash}.png
```

### Built output, never committed (gitignored; local copies plus the bucket in prod)

```
public/assets/
  tiles.3f9a1c2b.png           # packed sheet, 64×64 cells, 16 columns
  marker.a1b2c3d4.png
  buildings/library.9e8d7c6b.png
  avatars/base.front.0f1e2d3c.png + .json   # Phaser atlas per strip
  audio/music/pier.77aa11bb.opus
bootstrap/cache/assets.json    # logical name → hashed path; the manifest reads this
resources/maps/ground.png      # unhashed preview so Tiled opens maps with real tiles
```

**Naming rules, for every kind:**

- Lowercase kebab-case slugs: `[a-z0-9-]+`. A slug is the same string as the
  building's name in Tiled, the item's slug, or the room's slug.
- Anything with several parts gets a folder per slug: `{kind}/{slug}/{part}.png`.
  A part is never added to the slug with a hyphen, so names can't collide.
- One image is one file at native pixel size. The build does any scaling.
- A working file sits beside its PNG under the same name (`.aseprite`,
  `.ase`). Anything over 1 MB stays out of Git (§8).

## 4. Build step

**Recommendation:** one command, `php artisan hushfield:build-assets`. It uses
PHP GD, which is already installed locally, so there's no new dependency. It:

1. **Packs the tile sheet** from `resources/art/tiles/*.png` in the order of
   `tiles.json`. It scales each tile with nearest-neighbour (2× until decision
   A changes that) into 64×64 cells, 16 columns wide.
2. **Writes the tileset block** (name `placeholder`, image size, columns,
   tilecount, and a `slug` property on each tile) into every
   `resources/maps/*.json`, and the `ground.png` preview for Tiled. This also
   fixes the out-of-date 32-px tileset in today's maps.
3. **Packs avatar and wearable strips** into Phaser atlas JSON, checking that
   frame sizes are exact.
4. **Copies everything else**, checking each file against its kind's rules:
   PNG, RGBA, the canvas width rule, nothing below the footprint diamond.
5. **Hashes every output** (first 8 hex of its SHA-256) into its file name and
   writes `bootstrap/cache/assets.json`.
6. **Checks provenance**: every source must have an entry in
   `provenance.json`. A licence of `unknown` is a warning for now and becomes a
   build error before launch.

It runs in `composer setup`, `composer dev` (once at start), CI before tests,
and the Laravel Cloud **build** commands. Build-time changes to the filesystem
persist into the image.

**Keeping map tiles stable.** A tile's GID depends only on its position in
`tiles/tiles.json`:

```json
["grass", "sand", "stone", "water", "hedge", "wood"]
```

- The list is append-only. A new tile goes on the end, so no existing GID ever
  moves. It matches today's slots exactly, so no map needs to change.
- To retire a tile, replace its slug with `null`. The slot packs as a
  transparent cell, and `hushfield:import-map` refuses any map that still uses
  it, or any GID beyond the end of the list.
- Each tile's `slug` property means a later tool can remap GIDs if we ever
  compact the list. We don't plan to.
- Swapping art (her grass for the pack's grass) keeps the slug and the slot.
  The sheet gets a new hash and maps don't change.

| Option                                       | For                                                                                                                | Against                                                                                                             |
| -------------------------------------------- | ------------------------------------------------------------------------------------------------------------------ | ------------------------------------------------------------------------------------------------------------------- |
| **Generate from single files (recommended)** | No hand-pasting; a diff shows which tile changed; provenance per file; the scale and validation rules live in code | One more command to run; build-time dependency on GD                                                                |
| Keep hand-assembling                         | Nothing to build                                                                                                   | Has already been done by hand three times; slot order lives only in the sheet; provenance can't be tracked per tile |
| Commit built sheets as well                  | Reviewers see the sheet in the PR; no build dependency                                                             | Generated binaries pile up in Git history and can drift from the sources                                            |
| Tiled image-collection tilesets (no sheet)   | No packing at all                                                                                                  | Phaser's tilemap layers render single-image tilesets; one request per tile                                          |
| Have Vite import and hash the art            | Hashing comes free                                                                                                 | Ties art to the JS bundle; can't cover runtime item uploads; still needs a packer                                   |

## 5. Cache busting and versioning

**Recommendation:** a content hash per file in its name, with **no global
`v{n}`**. Remove `HUSHFIELD_ASSET_VERSION`. The manifest's `version` becomes
the hash of `assets.json`.

- The manifest stays `Cache-Control: no-cache`, served with an ETag, so the
  edge revalidates it cheaply. Every file it lists is
  `public, max-age=31536000, immutable`.
- **When one tile changes**, only `tiles.*.png` gets a new URL. Furniture,
  buildings and avatars stay in browser caches.
- **During a deploy**, new files are uploaded before the new release goes live
  (§5a). An old client still holds the old manifest, keeps using old URLs, and
  they keep working because nothing is deleted at deploy. A protocol bump
  still forces a reload as it does today.
- **The CDN** needs no purges, ever. Hashed URLs never change content, and the
  manifest is always revalidated.
- **Safety net:** if an asset fails to load, the client refetches the manifest
  once and retries. This covers serving from the app, where a deploy replaces
  the whole filesystem.
- **Pruning:** a scheduled `hushfield:prune-assets` deletes bucket files that
  no current index or item references and that are older than 30 days.

| Option                          | For                                                                 | Against                                                                                                                                                                                                          |
| ------------------------------- | ------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Per-file hash (recommended)** | Busts exactly what changed; can't be forgotten; old and new coexist | Needs the build index                                                                                                                                                                                            |
| Global `v{n}` folder            | Simple                                                              | Every bump re-downloads everything; easy to forget (a changed tile with the same frame size kept `v2`, so a cached grass could stay stale); envs pinned to an old `n` break; the old folder disappears on deploy |
| Query string `?v=hash`          | No rename                                                           | Some CDNs ignore query strings in the cache key; with one path, old and new can't coexist                                                                                                                        |

## 5a. Delivery

**Recommendation:** in production, everything is served from one public
Laravel Cloud bucket (R2), ideally on a custom domain such as
`assets.hushfield.…`. Locally and in CI, the app serves the same hashed files
from `public/assets/`. The client can't tell the difference, because it only
reads manifest URLs.

```
build:   hushfield:build-assets        → public/assets/*.hash.*, bootstrap/cache/assets.json
deploy:  hushfield:publish-assets      → upload to the `assets` disk, skipping keys that exist
         migrate --force, hushfield:import-map
live:    manifest = HUSHFIELD_ASSET_URL + paths from assets.json  (+ item art from the DB)
```

- A new disk, `assets`, in `config/filesystems.php`: `s3` (the bucket) in
  production, `public` locally. `HUSHFIELD_ASSET_URL` is the bucket's public
  URL in prod and unset locally, where it falls back to `asset('assets')`.
- Upload runs as a **deploy** command, which Cloud runs before the release
  goes live. Its filesystem changes don't persist, but uploads are external.
  Uploading only keys that don't exist yet makes it idempotent and fast.
- The bucket needs CORS (`GET` from the app's origins) and the Phaser loader
  needs `crossOrigin = 'anonymous'`. Without both, building hit-testing and
  furniture mirroring break (§1).
- **Local dev needs no S3.** `composer dev` builds the assets and the app
  serves them. Item uploads go to `storage/app/public` through `storage:link`.

| Option                                 | For                                                                                                                                                                              | Against                                                                                                                                           |
| -------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Bucket + CDN in prod (recommended)** | Old files survive deploys; static traffic stays off Octane workers; we need the bucket anyway for item uploads, because Cloud's app disk doesn't last across deploys or replicas | A bucket to pay for; CORS setup; one more deploy command                                                                                          |
| Serve from the app only                | Nothing to set up                                                                                                                                                                | Every deploy replaces the files, so mid-session clients lose old URLs; static requests hit the app; runtime uploads have nowhere persistent to go |

Not verified: Laravel Cloud's support for bucket CORS rules and custom
domains, and the cache headers it sets on static files in `public/`. Step 4
checks them.

## 6. Item art at runtime

**Recommendation:** split by what refers to the art.

- **Art that maps or code refer to** (tiles, buildings, walls, avatar base, UI,
  audio) **ships with the code.** A map can't use a building that doesn't
  exist, so they deploy together.
- **Art that catalog rows refer to** (furniture, wearables) **is uploaded in
  Filament** to the bucket, so a new item can go live without a deploy.

How it works:

- An `item_art` table: item, part (`0`–`3`, `front`, `back`), path, sha,
  width, height, bytes, uploader, provenance (author, source, licence), and
  `published_at`/`published_by`. Rows are never overwritten. A new upload is a
  new row with a new hash, so rolling back is instant.
- **Checks on upload:** PNG only; ≤ 256 KB; canvas exactly
  (wide + deep) × 32 px across; height ≤ 512; no opaque pixel outside the area
  allowed above the footprint diamond; wearable strips exactly N × 96 by 144.
  The file is **re-encoded with GD** before it's stored, which drops metadata
  and any payload hidden in the PNG.
- **Moderation:** uploading needs a staff permission. Art is a draft until
  someone presses "Publish art". Unpublishing reverts to the placeholder at
  once, and every action is logged. If an item's `wide` or `deep` changes, its
  art is flagged stale.
- **Missing art:** the tinted placeholder block stays as the fallback, and the
  item list in Filament shows an art status (missing, partial, published).
  `facingArt` still mirrors or borrows any missing facings.
- **Bulk:** `php artisan hushfield:import-item-art {dir}`, plus the same thing
  as a Filament "upload zip" action. It takes the handover layout (§10)
  without clicking through four uploads per item. It also seeds local dev
  from `database/seeders/art/` and moves over any repo item art from before
  this step.
- **Payload:** each item's art URLs go into the item payload (inventory, shop,
  placed furniture in snapshots), not the manifest. That keeps the manifest
  small as the catalog grows, and lets rooms load only what's placed.

| Option                                                  | For                                                                                       | Against                                                                                                             |
| ------------------------------------------------------- | ----------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------- |
| **Uploads for items, code for world art (recommended)** | Items stay fully self-serve; artists' batches import in bulk; per-item audit and rollback | A table, a form, checks and a bucket                                                                                |
| All art ships with code                                 | One pipeline; reviewed in PRs                                                             | Every new shop item needs a deploy, which defeats creating items in Filament                                        |
| Everything uploaded                                     | One pipeline                                                                              | A map can arrive before its building art; tiles still need packing and slot order; nothing goes through code review |

## 7. Loading

| When                                                | What                                                                                                                              |
| --------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------- |
| Boot                                                | Manifest, tile sheet, marker, hover, avatar base atlas, UI sprites. Aim for < 300 KB.                                             |
| On entering a room (with its map JSON, as today)    | Art for buildings on that map; art for furniture placed there (from the snapshot)                                                 |
| When a resident appears or changes outfit           | Their wearables' atlases, loaded once per slug                                                                                    |
| On placing a piece or opening the shop or inventory | That item's art; React thumbnails load lazily with `loading="lazy"`                                                               |
| After the first user gesture, on entering a room    | That room's music, streamed through an `<audio>` element rather than decoded whole into WebAudio (a decoded track is ≈ 10 MB/min) |

Limits to watch:

- **Texture size:** keep any one image ≤ 2048 px per side for older phones,
  and never above 4096. A 64-px sheet at 16 columns reaches 1024 px wide; at
  2048×2048 it fits 1024 tiles.
- **Texture memory** is width × height × 4 bytes. Remove building, furniture
  and wearable textures when leaving a room (`textures.remove`), unless the
  next room uses them too. That includes the flipped copies `FurnitureLayer`
  makes.
- **Requests:** HTTP/2 handles many small images well; Phaser loads 32 at
  once by default. Pack per slug, never one atlas for the whole catalog.
- **The manifest** stays small, since item URLs are no longer in it.

## 8. Licensing and provenance

`resources/art/provenance.json`. Keys are paths relative to `resources/`;
globs are allowed for batches.

```json
{
    "art/tiles/grass.png": {
        "by": "<artist name>",
        "licence": "commissioned",
        "received": "2026-10-26",
        "notes": "second, calmer grass"
    },
    "art/tiles/stone.png": {
        "by": "<artist name>",
        "licence": "commissioned",
        "received": "2026-10-25"
    },
    "art/tiles/sand.png": {
        "by": "unknown pack",
        "source": "tile_000 of spritesheet.png (352×352, 32-px grid)",
        "licence": "unknown",
        "notes": "replace or confirm before launch"
    },
    "art/tiles/water.png": {
        "by": "unknown pack",
        "source": "tile_110",
        "licence": "unknown"
    },
    "art/tiles/hedge.png": {
        "by": "unknown pack",
        "source": "tile_029",
        "licence": "unknown"
    },
    "art/tiles/wood.png": {
        "by": "unknown pack",
        "source": "tile_025",
        "licence": "unknown"
    }
}
```

- The build fails on any source without an entry. A licence of `unknown` is a
  warning until launch, then becomes an error.
- Uploaded item art keeps the same fields in `item_art`.
- If any licence needs attribution (CC-BY), we build a credits page from the
  file.
- **Masters and anything over 1 MB** (PSD, `.blend`, WAV) go in a private
  "art-sources" bucket or shared drive, under the same paths. Their
  provenance entry has `"master": "<location>"`. CI fails on any file over
  1 MB under `resources/art` or `resources/audio`.
- **No Git LFS.** Nothing that ships needs it at these sizes. Cloud's LFS
  support during builds is unverified. Orb clones of the Amp remote filter
  out blobs over 1 MB. GitHub LFS has bandwidth quotas.

## 9. Decisions for you

|     | Decision                                                                                                                                                                                           | Recommendation                                                                                                                              |
| --- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------- |
| A   | **Pixel scale.** Ground is 32-px art at 2×. Should buildings, furniture and avatars also be drawn at half size and doubled (chunky pixels that match the ground), or drawn 1:1 as the README says? | Draw everything at half size and double it at build, so pixels match. This decides the canvas rules the artist works to, so it comes first. |
| B   | **The unknown pack.** Name it and confirm the licence, or have the artist replace sand, water, hedge and wood.                                                                                     | Ask Luigi for the pack's name today; plan for replacement either way.                                                                       |
| C   | **Item art through Filament** rather than shipping with code.                                                                                                                                      | Yes (§6).                                                                                                                                   |
| D   | **Publishing.** Can one staff member publish, or does it need a second?                                                                                                                            | One, with an audit log, while the staff is small.                                                                                           |
| E   | **Bucket and domain.** Attach a public Cloud bucket and decide its domain.                                                                                                                         | Yes, when step 4 lands.                                                                                                                     |
| F   | **Where masters live.** A private bucket or a shared drive.                                                                                                                                        | Whichever the artist already uses.                                                                                                          |
| G   | **Shop gating.** Can an item be `in_shop` without published art?                                                                                                                                   | Allow it, with a warning in Filament.                                                                                                       |

## 10. Migration, in shippable steps

Each step is its own PR and leaves the game working.

1. **Sources and provenance.** Split `v2/tiles.png` back into
   `resources/art/tiles/{grass,sand,stone,water,hedge,wood}.png` (exact 2×, so
   nothing is lost). Add `tiles.json` with today's order, `provenance.json`,
   and the >1 MB CI check. Nothing at runtime changes.
2. **Build command plus hashes.** Add `hushfield:build-assets` (tiles packed by
   the registry, tileset blocks rewritten in the maps, UI copied, hashes,
   `assets.json`). The manifest reads the index, `v{n}` and
   `HUSHFIELD_ASSET_VERSION` go, and `public/assets/` is gitignored. Immutable
   headers for `/assets/*`. Wire the command into setup, dev, CI and the Cloud
   build commands. The client retries a failed load once after refetching the
   manifest. `hushfield:import-map` checks GIDs against the registry. Check:
   the packed sheet is pixel-identical to today's `v2/tiles.png`.
3. **Kind layout and lazy loading.** Building art and per-item folders
   (`items/{slug}/{0-3}.png`) go in now, **before the first file arrives**,
   while there's nothing to rename. Buildings and furniture load per room and
   are removed on leaving. Set `crossOrigin = 'anonymous'` on the loader.
   Until step 5, the artist's furniture goes into `database/seeders/art/items/`.
4. **Bucket.** Add the `assets` disk, `hushfield:publish-assets` as a deploy
   command, `HUSHFIELD_ASSET_URL` pointing at the bucket, CORS, and the
   scheduled prune. Verify on staging: an old tab keeps working across a
   deploy; requests from the CDN origin pass CORS; building hover still
   hit-tests.
5. **Item art in Filament.** The `item_art` table, upload with its checks,
   publish and unpublish, art status in the list, URLs in the item payload,
   `hushfield:import-item-art` and the zip action. Import whatever furniture
   art exists by then.
6. **Avatars and wearables.** Base avatar strips and their atlas from the
   repo; wearables through the step 5 pipeline (`front`/`back` parts). Load per
   resident in the room.
7. **Walls and audio**, when they're designed: `walls/{slug}/` packed into
   one atlas; room music under `resources/audio/music/{room-slug}`, loaded
   lazily after a gesture.
8. **Before launch:** `unknown` licences become a build error.

## 11. For the artist: how to hand over files

**Send** a zip, or a shared folder, laid out like this. Keep your working
files: send the `.aseprite` alongside the PNG if it's under 1 MB, and tell us
where anything bigger lives.

```
handover-2026-11-02/
  tiles/grass.png                 32×32, one block per file
  tiles/grass-flowers.png         a variant: same tile, new name
  buildings/library.png           one whole image per building
  items/love-seat/0.png           furniture: facings 0–3 (0 faces lower left)
  items/love-seat/1.png           leave out any facing that's a mirror of another
  items/red-beanie/front.png      wearable: strip of 96×144 frames, in the avatar brief's order
  items/red-beanie/back.png
  NOTES.txt                       anything not drawn by you: where it's from, its licence
```

**Rules**

- **Names:** lowercase, words joined with hyphens: `love-seat`, not
  `Love Seat.png` or `image.png`. Ask us for the slug. For a building it's the
  building's name in the map; for an item it's the slug in the catalog.
- **Size:** native pixels, no scaling, no smoothing, transparent background,
  PNG. We double tiles (and, once decision A is made, everything else) at
  build time.
- **Canvas:** for buildings and furniture, (wide + deep) × 32 px across, with
  the footprint diamond touching the bottom edge. See "Art" in
  `resources/maps/README.md`. Light comes from the top left.
- **Changing a file:** send it again under the same name. Never rename it to
  `-v2` or `-final`; we keep the history.
- **One thing per file.** No sheets: we pack them.
- **Anything that isn't yours** (a reference or a pack tile) must say so in
  `NOTES.txt`.

**What happens next:** world art (tiles, buildings, UI) goes into
`resources/art/` in a PR, and the build checks the canvas and provenance.
Item art is imported through Filament as a draft. You'll get a link to see it
in the game before it's published.
