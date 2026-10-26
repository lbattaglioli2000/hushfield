# Game assets plan

Where Hushfield's art and audio live, how they're named, built, versioned and
delivered, and how we get there from today. Written against `github/main` at
`bf34fbc` and revised with the decisions in §11. This is a plan: nothing in it
is built yet.

**In one paragraph:** The artist draws everything at half size: tiles,
buildings, furniture, walls, avatars and wearables. The build doubles it, so
every pixel in the game matches the ground's. Her source files live in the
repo under `resources/art/` (and `resources/audio/`), one file per tile,
building, panel or strip, named by slug. An artisan command builds what
ships. It packs the tile sheet in a fixed slot order, doubles everything,
checks canvases, gives each output a content hash in its name, and writes the
index the manifest is served from. Built files aren't committed. Production
serves them from the public Laravel Cloud bucket with immutable caching.
Locally the app serves them itself, and an orb can run Adobe's S3Mock to test
the bucket path. Masters go in a separate private bucket. Item art ships with code for now. Filament uploads come later,
when we want to require art.

---

## 1. Where things stand today

Checked against the code, not the brief.

| What                        | Where                                                                                                          | Notes                                                                                                                                                                                                                              |
| --------------------------- | -------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Ground tiles                | `public/assets/v2/tiles.png`, 384×64                                                                           | 6 slots, each a 32×32 block doubled to 64×64. Checked: an exact nearest-neighbour 2×, so it splits back losslessly. Slot `n` is Tiled GID `n+1`.                                                                                   |
| Other images                | `public/assets/v2/resident.png` (28×44), `marker.png`, `hover.png` (64×32)                                     | Listed by name in `config/hushfield.php` → `assets.images`.                                                                                                                                                                        |
| Buildings, furniture, walls | `buildings/{slug}.png`, `furniture/{slug}[-{rotation}].png`, `walls/{style}-{left\|right}[-door].png`          | **No art in any of them yet.** The conventions live in code and `resources/maps/README.md`, which specs them at 1:1 game pixels.                                                                                                   |
| Manifest                    | `AssetManifestController` at `/assets/manifest.json`, `no-cache`                                               | URLs are `{HUSHFIELD_ASSET_URL or /assets}/v{HUSHFIELD_ASSET_VERSION}/…`. Building, furniture and wall entries come from **globbing `public/assets/v{n}/` on the app server**, so a file that exists only on a CDN never shows up. |
| Client loading              | `RoomScene.preload()`                                                                                          | Loads the tile sheet, avatar, marker, hover and **every building, furniture and wall image in the manifest, at boot**. Map JSON already loads lazily per room and is served immutable by `MapController`.                          |
| Rendering                   | `render: { antialias: true, roundPixels: true }`, `Scale.RESIZE`                                               | Art is drawn 1:1 and nothing zooms. The canvas is sized in CSS pixels, so **on high-DPI screens the browser stretches it and blurs every pixel**. Nothing sets `image-rendering`.                                                  |
| Maps                        | `resources/maps/*.json`                                                                                        | Each embeds a `placeholder` tileset that is **out of date**: it says 32 px tall, but the sheet is 64. It works only because `IsoMap` passes the real sizes.                                                                        |
| Versioning                  | Renaming the `v1` folder to `v2`, plus a new default for `HUSHFIELD_ASSET_VERSION`                             | Every URL changes on every bump, and a server whose env still pins `1` breaks.                                                                                                                                                     |
| Storage                     | A public Cloud bucket is attached to the app; `aws/aws-sdk-php` and `league/flysystem-aws-s3-v3` are installed | Nothing uploads to it.                                                                                                                                                                                                             |
| CORS                        | No `crossOrigin` anywhere in `resources/js`                                                                    | **Serving from the bucket would break this.** `BuildingLayer` reads pixel alpha and `FurnitureLayer` draws flipped copies on a canvas; both fail on images loaded cross-origin without CORS.                                       |
| Tile sheet assembly         | By hand: crop, double, paste with Pillow or ImageMagick                                                        | Done by hand three times so far.                                                                                                                                                                                                   |
| Pack tiles                  | Slots 1 sand, 3 water, 4 hedge, 5 wood                                                                         | From a pack that was only for testing. **They're being removed**; all art will be hand drawn. Slots 0 (grass) and 2 (stone) are the artist's.                                                                                      |
| Item art                    | `Item.slug` (can't change once created); slots `head face body feet hand pin background furniture`             | Art is found by slug. There is no upload.                                                                                                                                                                                          |
| Audio                       | None                                                                                                           | Radio static is synthesised in `use-radio-sound.ts`.                                                                                                                                                                               |

Two latent bugs the plan fixes along the way:

- **A furniture name can mean two things.** `chair-2.png` could be item
  `chair-2` at facing 0, or item `chair` at facing 2. This is fixed by giving
  each item its own folder (§4). Wall names have the same shape.
- **Pixel sizes don't match.** Tiles are 32-px art shown at 2×, but the
  README specs buildings, furniture and walls at 1:1, so their pixels would be
  half the size of the ground's. This is resolved in §2.

## 2. Pixel scale: draw at half size, double at build

Decided for now: everything is drawn at half size and doubled, so one art
pixel is always 2×2 game pixels. These are the things that have to hold for it
to look right.

**Scale is one build setting, so it can change.** The game's geometry is fixed
in game pixels: 64×32 tiles and the canvas rules in the README. The art's
scale is a single setting, `config('hushfield.assets.scale')`, set to `2`. The
build expects each source to be its game size divided by the scale, and
multiplies by the scale with nearest-neighbour. Nothing else reads it.

- To try larger art while iterating, set the scale to `1`, and sources are
  drawn at full game size. No code changes.
- The setting can be overridden per kind (`scale_overrides: { avatars: 1 }`),
  so avatars can be tried at full size while the ground stays doubled. The
  catch: different scales mean different pixel sizes side by side, which is
  exactly what §1 called a bug. Use overrides to experiment, not to ship.
- Changing a kind's scale means redrawing its art at the new source size. The
  build rejects any source whose size doesn't match, so nothing stretches by
  accident. Outputs get new hashes, so caches clear themselves.
- Only integer scales are allowed. The tables below show the canvases at
  scale 2.

**What she draws (art pixels) and what the game shows:**

| Kind                      | Source canvas                                                                                                                                                                                                      | In game                                                                        |
| ------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ | ------------------------------------------------------------------------------ |
| Ground tile               | 32×32 block; its top face is a 32×16 diamond at the top                                                                                                                                                            | 64×64, lifted 16 px                                                            |
| Building, furniture       | (wide + deep) × 16 across. The footprint diamond is (wide + deep) × 8 tall at the bottom: bottom corner at x = wide × 16, left corner wide × 8 above the bottom, right corner deep × 8 above it. Any height above. | ×2. A 1×1 stool is 32 px across in art, a 2×1 love seat 48, a 7×5 building 192 |
| Wall panel                | 16 × 56: the floor edge climbs 8 px across it, and the face stands 48 px above that edge                                                                                                                           | 32 × 112                                                                       |
| Avatar and wearable frame | 48 × 72, with a fixed anchor                                                                                                                                                                                       | 96 × 144                                                                       |
| Marker, hover             | 32 × 16 diamond                                                                                                                                                                                                    | 64 × 32                                                                        |

Every rule in the README's "Art" and "Wall panels" sections halves exactly,
because they're all multiples of 16 game pixels. The 2:1 isometric edges stay
2:1, which gives clean pixel-art lines in art pixels.

**Double at build, not at runtime.** The tilemap needs full-size tiles anyway,
and `BuildingLayer` (alpha hit-testing, strips half a tile wide),
`FurnitureLayer` (flipped copies) and `WallLayer` all assume one texture pixel
is one game pixel. Doubling at build changes none of them. Scaling at runtime
would touch every layer and every hit-test.

- Cost: textures take 4× the GPU memory (a 7×5 building about 0.8 MB) and
  files grow about 1.3×. Measured on today's sheet: 1.85 KB at half size,
  2.34 KB doubled. Revisit only if texture memory becomes a problem (§9).
- Integer 2× with nearest-neighbour only. Never smoothing, and never any
  other factor.

**Keep the pixel grid aligned.**

- Static things already land on even game pixels: the tile grid works in
  multiples of 16 and 32, and every doubled canvas has even sizes, so anchors
  are even.
- Moving things don't. Avatars move continuously and `roundPixels` rounds to
  whole pixels, so an avatar standing at an odd x sits half an art pixel off
  the ground's grid. You barely see it while walking, but you do when they
  stand still. Fix: snap the _drawn_ position to even game pixels. The
  logical position, chat bubbles and name labels stay unsnapped.
- If a camera zoom is ever added, it must be an integer.

**Keep pixels crisp.**

- Keep `antialias: true` so text stays smooth, and set NEAREST filtering on
  art textures, so anything that does scale (a preview, a future zoom) stays
  sharp.
- High-DPI screens: set `image-rendering: pixelated` on the canvas. At DPR 2
  or 3 that's exactly crisp. At DPR 1.25 or 1.5 (many Windows laptops),
  pixels come out alternately 1 and 2 device pixels wide. The alternative is
  rendering at device resolution with an integer zoom, which is more work.
  Start with `pixelated` and check it on a 1.5× screen (step 4).
- Text, chat bubbles, labels and door glows stay at full resolution, drawn by
  code. Mixing resolutions like this is intended.

**Detail budget.** A 1×1 piece has 32 art pixels across, every line is at
least 2 game pixels wide, and the smallest readable sign text is about 3×5
art pixels. That suits buildings and furniture. **Avatars are the risky
case**: a 48×72 frame leaves a face a handful of pixels wide, and every
wearable repeats that decision for 7 slots × 2 facings × every walk frame.
Before more of the avatar brief is written, have her draw one base front
strip and one hat at half size, and look at them in the game over the ground.

**Guards in the build.** It rejects any file whose size breaks its kind's
rule in art pixels at its scale. When the scale is above 1, it also rejects
a file that is already scaled up (scale × the expected size, with every
block of that size one colour), which is the easy mistake of exporting at
200%.

**Ordering.** Step 3 has landed: the build doubles buildings, furniture and
walls, so half-size art for them can arrive now.

## 3. Size expectations

Sources are half-size pixel art, so Git copes for years:

| Kind                                           | Per source file | Plausible count | Total in Git                     |
| ---------------------------------------------- | --------------- | --------------- | -------------------------------- |
| Ground tile (32×32)                            | 0.3–1 KB        | 100–300         | < 0.3 MB                         |
| Building (≈ 200×250)                           | 3–15 KB         | 30–60           | < 1 MB                           |
| Furniture (up to 4 facings)                    | 1–3 KB each     | 300–1000 items  | ≈ 2–10 MB                        |
| Wearable (front + back strips of 48×72 frames) | 2–8 KB          | 500–2000 items  | ≈ 3–15 MB                        |
| `.aseprite` working files                      | ≈ 1–3× the PNG  |                 | roughly doubles that             |
| Room music (Opus ≈ 96 kbps, 2-min loop)        | ≈ 1.5 MB        | 10–30           | 15–45 MB                         |
| Masters (PSD, WAV, `.blend`)                   | 5–200 MB        |                 | **private bucket, not Git** (§7) |

## 4. Recommended layout

### Sources, committed

```
resources/
  art/
    provenance.json            # who drew each file, when it arrived (§10)
    tiles/
      tiles.json               # slot registry: type/height, append-only (§5)
      grass/full.png           # 32×32 full cube; one folder per tile type
      grass/full.aseprite      # optional working file, same name
      stone/full.png
      stone/half.png           # half slab: same canvas, sides half as tall (8 px)
                               # room later for full-2.png, edge pieces
    buildings/
      library.png              # one whole image per building slug
    walls/
      {style}/left.png         # 16×56 panel for the top-left wall
      {style}/right.png
      {style}/left-door.png    # optional doorway panels
      {style}/right-door.png
      {style}/left-window.png  # reserved; the game doesn't draw windows yet
    items/
      {slug}/0.png … 3.png     # furniture facings; missing ones mirror or borrow (facingArt)
      {slug}/front.png         # wearable: strip of 48×72 frames
      {slug}/back.png
    avatars/
      base/front.png           # strip of 48×72 frames, fixed anchor
      base/back.png
    ui/
      marker.png               # 32×16
      hover.png
      resident-placeholder.png # today's resident.png until avatars land
  audio/
    music/{room-slug}.opus     # shipped encodes only; masters go to the private bucket
    sfx/{slug}.opus            # e.g. recorded static, if it replaces the synth
```

Art drawn by React (the radio's knobs and dial) goes through Vite, not the
manifest.

### Built output, never committed

Gitignored. In production a copy is uploaded to the bucket.

```
public/assets/
  tiles.3f9a1c2b.png           # packed sheet: 64×64 cells, 16 columns
  marker.a1b2c3d4.png
  buildings/library.9e8d7c6b.png
  walls/brick/left.5c4b3a29.png
  items/love-seat/0.0a1b2c3d.png
  avatars/base/front.0f1e2d3c.png + .json   # Phaser atlas per strip
  audio/music/pier.77aa11bb.opus
bootstrap/cache/assets.json    # logical name → hashed path; the manifest reads this
resources/maps/ground.png      # unhashed preview, gitignored, so Tiled shows real tiles
```

**Naming rules, for every kind:**

- Lowercase kebab-case slugs, `[a-z0-9-]+`. A slug is the same string as the
  building's name in Tiled, the wall style in the map's `walls` property, the
  item's slug, or the room's slug.
- Anything with several parts gets a folder per slug:
  `{kind}/{slug}/{part}.png`. Parts are a fixed list per kind, so names can't
  collide.
- One image per file, at art size. The build does all scaling and packing.
- A working file sits beside its PNG under the same name (`.aseprite`). Any
  file over 1 MB goes to the masters bucket instead (§7).

## 5. Build step

**The game still loads one tile sheet.** What goes away is pasting tiles into
it by hand. Her individual files become the source, and the build packs them
into the same kind of single PNG, with a hash in its name.

**Recommendation:** one command, `php artisan hushfield:build-assets`. It uses
PHP GD, which is already installed, so there's no new dependency. It:

1. **Packs the tile sheet** from `resources/art/tiles/{type}/{height}.png` in the order of
   `tiles.json`, doubled into 64×64 cells, 16 columns wide. A slot with no
   source file gets a flat placeholder block in the shared block shape,
   tinted by its slug, just as items without art are drawn today.
2. **Writes the tileset block** (name `placeholder`, image size, columns,
   tilecount, and a `slug` property on each tile) into every
   `resources/maps/*.json`, plus the `ground.png` preview for Tiled. This also
   fixes today's out-of-date 32-px tileset.
3. **Doubles and checks everything else**: PNG, RGBA, the canvas rule for its
   kind in art pixels, nothing below the footprint diamond or floor edge, and
   not already doubled (§2).
4. **Packs avatar and wearable strips** into Phaser atlas JSON and checks
   that frame sizes are exact.
5. **Hashes every output** (the first 8 hex digits of its SHA-256) into its
   file name and writes `bootstrap/cache/assets.json`.
6. **Checks provenance**: every source file must have an entry in
   `provenance.json` (§10).

It runs in `composer setup`, once at the start of `composer dev`, in CI before
the tests, and as a Laravel Cloud **build** command. Changes a build command
makes to the filesystem persist into the image.

**Keeping map tiles stable.** A tile's GID depends only on its position in
`tiles/tiles.json`:

```json
[
    "grass/full",
    "sand/full",
    "stone/full",
    "water/full",
    "hedge/full",
    "wood/full"
]
```

- The list is append-only. New tiles go on the end, so no existing GID ever
  moves. It matches today's slots exactly, so no map changes.
- Each entry names a tile type and a slab height, the file
  `tiles/{type}/{height}.png`. Today's six slots are the `full` heights. A
  `half` slab is appended as a new slot when it arrives and packed into the
  sheet; no map uses one until elevation exists (later, not planned here).
- Removing the pack tiles keeps their slugs. `sand`, `water`, `hedge` and
  `wood` have no source file until she draws them, so they pack as
  placeholders and every map keeps working.
- Retiring a tile: replace its slug with `null`. `hushfield:import-map` then
  refuses any map that still uses it, or any GID past the end of the list.
- Swapping art keeps the slug and the slot. The sheet gets a new hash, and
  maps don't change.

| Option                                       | For                                                                                                     | Against                                                                                                         |
| -------------------------------------------- | ------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------- |
| **Generate from single files (recommended)** | No hand-pasting; doubling and checks live in code; a diff shows which tile changed; provenance per file | One more command to run; GD at build time                                                                       |
| Keep assembling by hand                      | Nothing to build                                                                                        | Already done by hand three times; slot order lives only in the sheet; each kind would need doubling by hand too |
| Commit built files as well                   | PRs show the result; no build dependency                                                                | Generated binaries pile up in history and can drift from their sources                                          |
| Tiled image-collection tilesets (no sheet)   | No packing                                                                                              | Phaser's tilemap layers render single-image tilesets; one request per tile                                      |
| Let Vite import and hash the art             | Hashing comes free                                                                                      | Ties art to the JS bundle; can't cover future uploads; still needs a packer and a doubler                       |

## 6. Cache busting and versioning

**Recommendation:** a content hash per file in its name, and **no global
`v{n}`**. Remove `HUSHFIELD_ASSET_VERSION`. The manifest's `version` becomes
the hash of `assets.json`.

- The manifest stays `Cache-Control: no-cache` with an ETag, so it's cheap to
  revalidate. Every file it lists is `public, max-age=31536000, immutable`.
- **When one tile changes**, only `tiles.*.png` gets a new URL. Everything
  else stays cached.
- **During a deploy**, new files reach the bucket before the release goes
  live (§7). Old clients keep their old manifest, and its URLs still work
  because a deploy deletes nothing. A protocol bump still forces a reload, as
  it does today.
- **The CDN** never needs purging: a hashed URL never changes content, and the
  manifest is always revalidated.
- **Safety net:** if an asset fails to load, the client refetches the manifest
  once and retries. This covers serving from the app, where each deploy
  replaces the files.
- **Pruning:** a scheduled `hushfield:prune-assets` deletes bucket files that
  no current index refers to and that are older than 30 days.

| Option                          | For                                                                 | Against                                                                                                                                                                                             |
| ------------------------------- | ------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Per-file hash (recommended)** | Busts exactly what changed; can't be forgotten; old and new coexist | Needs the build index                                                                                                                                                                               |
| Global `v{n}` folder            | Simple                                                              | Every bump re-downloads everything. Easy to forget: her grass replaced a tile without a bump, so a cached grass can stay stale. Envs pinned to an old `n` break. The old folder vanishes on deploy. |
| Query string `?v=hash`          | No renames                                                          | Some CDNs leave query strings out of the cache key; with one path, old and new can't coexist                                                                                                        |

## 7. Delivery and storage

**Production:** everything is served from the public bucket already attached
in Laravel Cloud (R2), ideally on a custom domain such as
`assets.hushfield.…`. **Locally and in CI**, the app serves the same hashed
files from `public/assets/`. The client can't tell the difference, because it
only reads URLs from the manifest.

```
build:   hushfield:build-assets     → public/assets/*.{hash}.*, bootstrap/cache/assets.json
deploy:  hushfield:publish-assets   → upload to the `public` disk under assets/, skipping keys that exist
         migrate --force, hushfield:import-map
live:    manifest = HUSHFIELD_ASSET_URL + paths from assets.json
```

- The `public` disk is the public bucket (Laravel Cloud attaches it under
  that name, replacing Laravel's local `public` disk, which nothing in the
  app uses) and `private` the masters bucket. Locally both are folders, or
  S3Mock buckets when `PUBLIC_DISK_BUCKET` and `PRIVATE_DISK_BUCKET` are
  set. `HUSHFIELD_ASSET_URL` is the bucket's public URL; the manifest uses
  `{HUSHFIELD_ASSET_URL}/assets/` only once the running build is published
  (a flag publish sets in the cache), and `asset('assets')` until then, so
  a deploy without the publish command or the URL still works.
- The upload runs as a **deploy** command, which Cloud runs before the
  release goes live. Uploading only keys that don't exist yet makes it fast
  and safe to repeat.
- The bucket needs a CORS rule allowing `GET` from the app's origins, and the
  Phaser loader needs `crossOrigin = 'anonymous'`. Without both, building
  hit-testing and furniture mirroring break.

**Local S3 in the orb, for testing the bucket path.** Plain local dev and CI
stay on the `public` disk, with no S3 needed. To test publishing, CORS and
cross-origin loading before they reach production:

We use [Adobe S3Mock](https://github.com/adobe/S3Mock) (Apache-2.0), not
MinIO, because MinIO's licensing changed. The orb has no Docker daemon, so it
runs as S3Mock's executable jar:

- `.agents/setup` installs a Java 21 runtime (Temurin JRE, about 50 MB) and a
  pinned `s3mock-<version>-exec.jar` from Maven Central (about 30 MB).
- `.amp/services.yaml` runs it on port 9090 behind a portal, with
  `COM_ADOBE_TESTING_S3MOCK_STORE_INITIAL_BUCKETS=hushfield-assets,hushfield-masters`,
  `COM_ADOBE_TESTING_S3MOCK_STORE_ROOT=storage/s3mock` (gitignored) and
  `COM_ADOBE_TESTING_S3MOCK_STORE_RETAIN_FILES_ON_EXIT=true`.
- Switch it on with `AWS_ENDPOINT=http://127.0.0.1:9090`,
  `AWS_USE_PATH_STYLE_ENDPOINT=true`, `AWS_BUCKET=hushfield-assets`, and
  `HUSHFIELD_ASSET_URL` set to the S3Mock portal URL plus
  `/hushfield-assets`.

Checked against S3Mock 5.2.3 in this orb, through Laravel's own `s3` driver
and with curl:

| Need                                                    | Result                                                                                                                                                                                                                                                                                                                              |
| ------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Path-style access                                       | ✅ Works. It's the only style it supports; virtual-host requests return 404.                                                                                                                                                                                                                                                        |
| What `publish-assets` uses (put, exists, list, `url()`) | ✅ All work. `Cache-Control: …immutable` and `Content-Type` set on upload come back on GET.                                                                                                                                                                                                                                         |
| CORS for cross-origin image loads                       | ✅ Every response, preflight included, sends `Access-Control-Allow-Origin: *`. Gap: it can't be configured, so a wrong CORS rule can't be tested locally, only that `crossOrigin` loads work.                                                                                                                                       |
| Public versus private buckets                           | ❌ **Not enforced.** It ignores credentials, so unsigned uploads succeed, and an object uploaded as `private` reads back anonymously. Bucket policies and bucket ACLs aren't implemented. The two buckets show that the right files go to the right bucket, not that masters are hidden. That has to be checked on Cloud.           |
| Presigned URLs                                          | ⚠️ `temporaryUrl()` builds one, but S3Mock doesn't check signature or expiry: a bogus one returns 200. The plan uses none today. When Filament uploads arrive, test signatures on staging.                                                                                                                                          |
| Persisting to a folder                                  | ✅ Store root plus retain-on-exit kept both buckets and their objects across a restart. Caveat: upstream says reusing data across restarts isn't officially supported, and the format changed in 5.x. Treat the folder as a cache: pin the version, and let `hushfield:publish-assets` (which uploads anything missing) rebuild it. |

S3Mock covers what the orb test needs. Its one real gap, unenforced privacy,
is shared by every lightweight mock. If something more faithful is needed
later, the next licence-safe choice is
[S3Proxy](https://github.com/gaul/s3proxy) (Apache-2.0, also a Java jar). It
has a filesystem backend, configurable CORS, real auth, and `private` versus
`public-read` object ACLs. It has no bucket policies either. Avoid Garage
(AGPL) and LocalStack (now needs an account).

Not verified: whether a portal answers cross-origin image requests from the
app's portal without the viewer's Amp sign-in getting in the way. If it
doesn't, the orb test proves upload and indexing, and cross-origin loading is
checked on staging instead.

**Masters go in a second, private bucket (decided).** Every file in the
public bucket can be downloaded by anyone who guesses its path, so PSDs and
unreleased items would leak, and their size would count against public
egress. Masters are laid out under the same paths as `resources/`, and
uploaded with any S3 client (Cyberduck, rclone) using that bucket's own
keys. The orb's `hushfield-masters` bucket exists only to test tooling; real
masters live only in Cloud.

**What you set up in Laravel Cloud yourself:**

1. **Create the private bucket**: Laravel Object Storage, visibility
   **Private**, named something like `hushfield-masters`.
2. **Create credentials for it**: an access key and secret scoped to that
   bucket, read/write. Keep them in a password manager, and give them to the
   artist's S3 client. They don't go in the app's environment. The app
   doesn't read masters, so the bucket doesn't need attaching to the app.
3. **Public bucket** (already attached): note its public URL for
   `HUSHFIELD_ASSET_URL`, add a custom domain if you want one, and add a CORS
   rule allowing `GET` from the app's origins.

Not verified: whether Cloud offers per-bucket keys usable outside the app,
and whether it supports bucket CORS rules and custom domains. If it doesn't
offer such keys, the fallback is to attach the masters bucket to the app
under its own disk and add an upload command. Step 5 checks all of this.

| Option                                       | For                                                                                                | Against                                                                                            |
| -------------------------------------------- | -------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------- |
| **Bucket + CDN in production (recommended)** | Old files survive deploys; static traffic stays off Octane workers; the bucket is already attached | CORS setup; one more deploy command                                                                |
| Serve from the app only                      | Nothing to set up                                                                                  | Every deploy replaces the files, so mid-session clients lose old URLs; static requests hit the app |

## 8. Item art

**For now, item art ships with code.** Furniture and wearables live in
`resources/art/items/{slug}/` and go through the same build, hashing and
publishing as everything else. A new item's art goes out with the next deploy.
Until then the item draws as its tinted placeholder.

- Items may be in the shop without art. The Filament item list shows an art
  status (none, partial, complete), read from `assets.json`.
- `facingArt` still mirrors or borrows missing facings.
- The manifest lists item art under `items`, by slug and part. It's loaded per
  room, not at boot (§9).

**Later, when we want to require art:** uploads through Filament.

- Upload to the bucket under `items/{slug}/{part}.{hash}.png`, with an
  `item_art` row per upload: path, sha, size, uploader, `published_at` and
  `published_by`. Rows are never overwritten, so rolling back is instant.
- Checks on upload: the same art-pixel canvas rules as the build. The file is
  doubled and **re-encoded with GD**, which drops metadata and anything
  hidden in the PNG. Limits: ≤ 64 KB as uploaded, ≤ 256 art pixels tall.
- **One staff member can publish.** Every upload, publish and unpublish is
  logged. Unpublishing goes back to the placeholder at once. Changing an
  item's `wide` or `deep` marks its art stale.
- `hushfield:import-item-art {dir}` and a Filament zip action take the
  handover layout (§13) in bulk, and move the repo's item art across.
- Item art URLs move from the manifest into the item payload, so the manifest
  stays small as the catalog grows.
- Then switch on enforcement: an item can't be `in_shop` until all its parts
  are published.

| Option                                          | For                                                      | Against                                                                   |
| ----------------------------------------------- | -------------------------------------------------------- | ------------------------------------------------------------------------- |
| **Ship with code now, uploads later (decided)** | One pipeline while the catalog is small; reviewed in PRs | A new item's art needs a deploy                                           |
| Uploads now                                     | Fully self-serve items                                   | A table, a form, checks and moderation before there's any art to moderate |

## 9. Loading

| When                                                   | What                                                                                                                       |
| ------------------------------------------------------ | -------------------------------------------------------------------------------------------------------------------------- |
| Boot                                                   | Manifest, tile sheet, marker, hover, base avatar atlas, UI sprites. Aim for < 300 KB.                                      |
| On entering a room (with its map JSON, as today)       | That map's buildings and wall style; the furniture placed there, taken from the snapshot                                   |
| When a resident appears or changes outfit              | Their wearables' atlases, loaded once per slug                                                                             |
| When placing a piece, or opening the shop or inventory | That item's art; React thumbnails use `loading="lazy"`                                                                     |
| After the first user gesture, on entering a room       | That room's music, streamed through an `<audio>` element. Decoding a whole track into WebAudio costs about 10 MB a minute. |

Limits to watch:

- **Texture size:** keep any one image ≤ 2048 px per side for older phones,
  and never above 4096. The doubled sheet at 16 columns is 1024 px wide; at
  2048×2048 it holds 1024 tiles.
- **Texture memory** is width × height × 4 bytes, and doubling makes it 4×.
  On leaving a room, remove building, furniture, wall and wearable textures
  (`textures.remove`), unless the next room uses them too. That includes
  the flipped copies `FurnitureLayer` makes.
- **Requests:** HTTP/2 handles many small images well, and Phaser loads 32 at
  once by default. Pack atlases per slug, never one for the whole catalog.

## 10. Provenance

All art will be hand drawn by our artist, so provenance is about catching
anything that isn't hers: test art, references or pack tiles. The sand,
water, hedge and wood pack tiles never enter `resources/art/`.

`resources/art/provenance.json` has keys relative to `resources/`. Globs are
allowed, one entry per handover:

```json
{
    "art/tiles/grass/full.png": {
        "by": "<artist>",
        "received": "2026-10-26",
        "notes": "second, calmer grass"
    },
    "art/tiles/stone/full.png": { "by": "<artist>", "received": "2026-10-25" },
    "art/items/love-seat/*.png": { "by": "<artist>", "received": "2026-11-02" }
}
```

- The build fails on a source file with no entry. A new file has to be
  claimed by someone, so a stray test image can't ship unnoticed.
- A master's entry adds `"master": "<path in the masters bucket>"`.
- CI fails on any file over 1 MB under `resources/art` or `resources/audio`.
- **No Git LFS.** Nothing that ships needs it at these sizes. Cloud's LFS
  support in builds is unverified, orb clones of the Amp remote filter out
  blobs over 1 MB, and GitHub LFS has bandwidth quotas.
- The old pack tiles stay in Git history inside earlier `tiles.png`
  versions. The repo is private, and nothing ships from history.

## 11. Decisions

|     | Decision                   | Outcome                                                                                                                                                                                                 |
| --- | -------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| A   | Pixel scale                | **Half size, doubled at build, for now** (§2). Scale is one build setting (`assets.scale`), so larger art can be tried later without code changes. Prove it on one avatar strip and one wearable first. |
| B   | Third-party pack           | **Removed.** Its slots pack as placeholders until she draws them.                                                                                                                                       |
| C   | Item art through Filament  | **Later.** Item art ships with code for now (§8).                                                                                                                                                       |
| D   | Who publishes              | **One staff member**, with an audit log, once uploads exist.                                                                                                                                            |
| E   | Bucket                     | **Use the public bucket already attached in Cloud.** Adobe S3Mock in the orb for testing (§7); not MinIO.                                                                                               |
| F   | Masters                    | **A second, private bucket** (§7). You create it and its keys in Cloud.                                                                                                                                 |
| G   | Art-less items in the shop | **Allowed for now.** Enforced when Filament uploads land.                                                                                                                                               |

## 12. Migration, in shippable steps

Each step is its own PR and leaves the game working.

1. **Tile sources and registry.** Split her grass and stone out of
   `v2/tiles.png` into `resources/art/tiles/` (losslessly). Add `tiles.json`
   with all six slugs in today's order, `provenance.json`, and the CI check
   for files over 1 MB. The pack tiles get no source file. Nothing changes at
   runtime yet.
2. **Build command and hashes.** Add `hushfield:build-assets` for tiles (with
   placeholders for slots that have no art) and UI, scaled by
   `assets.scale` (2). It rewrites the tileset
   blocks in the maps, hashes outputs and writes `assets.json`. The manifest
   reads the index; `v{n}` and `HUSHFIELD_ASSET_VERSION` go, and
   `public/assets/` is gitignored. Add immutable headers for `/assets/*`, and
   run the command in setup, dev, CI and the Cloud build commands. The client
   retries a failed load once after refetching the manifest, and
   `hushfield:import-map` checks GIDs against the registry. Check: the grass
   and stone cells are pixel-identical to today's sheet.
3. **Art pixels for every kind.** Double buildings, furniture and walls at
   build, with the canvas checks and the already-doubled guard. Move to
   per-slug folders (`items/{slug}/{0-3}.png`, `walls/{style}/left.png`)
   while there's no art to rename. Restate the README's "Art" and "Wall
   panels" sections in art pixels, and add an art status column to the item
   list. **This must land before her first building, furniture or wall.**
4. **Crisp rendering and lazy loading.** Snap avatars' drawn position to even
   pixels. Set NEAREST filtering on art textures and `image-rendering:
   pixelated` on the canvas, and check on a 1.5× screen. Load buildings,
   walls and furniture per room and remove them on leaving. Set
   `crossOrigin = 'anonymous'` on the loader.
5. **Bucket.** Publish to the `public` disk (the attached public bucket)
   with `hushfield:publish-assets` as a deploy command. Point `HUSHFIELD_ASSET_URL`
   at it, add the CORS rule and the scheduled prune. You create the private
   masters bucket and its keys in Cloud (§7). Add S3Mock to the orb
   (`.agents/setup`, `.amp/services.yaml`). Verify on staging: an old tab keeps working across
   a deploy, cross-origin loads pass CORS, and building hover still
   hit-tests.
6. **Avatars and wearables.** Start with the one-strip-and-one-hat test from
   §2. Then the base avatar strips and atlas, and wearables under
   `items/{slug}/front|back.png`, loaded per resident in the room.
7. **Audio.** Room music under `resources/audio/music/{room-slug}.opus`,
   loaded lazily after a gesture.
8. **Later, to enforce item art:** Filament uploads to the bucket, one-staff
   publish, the import command, URLs in the item payload. Then the shop
   requires published art.

## 13. For the artist: how to hand over files

**Send** a zip or a shared folder laid out like this. Send the `.aseprite`
beside each PNG if it's under 1 MB. Put anything bigger in the masters bucket
and tell us its path.

```
handover-2026-11-02/
  tiles/grass/full.png            32×32 full cube, a folder per tile type
  tiles/stone/half.png            32×32 half slab of the same tile
  buildings/library.png           one whole image per building
  walls/brick/left.png            16×56 panel; right.png, and left-door.png / right-door.png if any
  items/love-seat/0.png           furniture facings 0–3 (0 faces lower left), 48 wide for a 2×1
  items/love-seat/1.png           leave out any facing that mirrors another
  items/red-beanie/front.png      wearable: strip of 48×72 frames, in the avatar brief's order
  items/red-beanie/back.png
  NOTES.txt                       anything you want us to know
```

**Draw at half size, for now.** Everything is doubled in the game, to match
the ground. If we try a larger size later, we'll send new canvas sizes. Don't
change size on your own: the build rejects any file that doesn't match.

| Kind                      | Your canvas                                                                                                                                                                                                                              |
| ------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Ground tile, full cube    | 32×32: top face a 32×16 diamond on rows 0–15 (top corner on row 0), sides 16 tall at the canvas's edges (rows 8–24), bottom corner on row 31. Tufts may poke above the top face.                                                         |
| Ground tile, half slab    | The same 32×32 canvas, top face and bottom edge, with sides 8 tall (rows 16–24), so the top face starts on row 8. Bottom-aligned.                                                                                                        |
| Building or furniture     | (wide + deep) × 16 across, with the footprint diamond (wide + deep) × 8 tall touching the bottom edge, its bottom corner wide × 16 from the left. A 1×1 stool is 32 across, a 2×1 love seat 48, a 7×5 building 192. As tall as it needs. |
| Wall panel                | 16 wide × 56 tall (or taller): the floor edge climbs 8 across the bottom, and the face is 48 tall above it. `walls/{style}/left.png`, `right.png`, optional `left-door.png`, `right-door.png`                                            |
| Avatar and wearable frame | 48×72                                                                                                                                                                                                                                    |

**Rules**

- **Names:** lowercase, words joined with hyphens: `love-seat`, not
  `Love Seat.png` or `image.png`. Ask us for the slug: a building's is its
  name in the map, an item's is its slug in the catalog.
- **Export at 100%.** No scaling, no smoothing, transparent background, PNG.
  The build rejects files that have already been doubled.
- **Light** comes from the top left. Full rules are in "Art" and "Wall
  panels" in `resources/maps/README.md`.
- **Changing a file:** send it again under the same name. Never rename it to
  `-v2` or `-final`; Git keeps the history.
- **One thing per file.** No sheets; we pack them.
- **Ground tiles** go in a folder per tile type, one file per height:
  `tiles/stone/full.png` and `tiles/stone/half.png`. The build checks each
  stands on the bottom of the canvas with sides the right height for its
  name. Ask before sending other kinds of tile file (edge pieces, a second
  version of a height); the build doesn't take them yet.

**What happens next:** your files go into `resources/art/` in a PR. The build
checks every canvas, and you get a link to see them in the game.
