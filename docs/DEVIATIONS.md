# Deviations from upstream Koken

This fork tracks [modufolio/Koken-App](https://github.com/modufolio/Koken-App) (Koken 1.3.3). Every change that is not in upstream is listed here, with the commit that made it and the files it touched, so the fork can be rebased, audited, or explained without digging through history.

**Keep this file current.** Any commit that changes behaviour, schema, config, or tooling relative to upstream gets a row here in the same commit. README-only and version-bump commits do not need a row, but a new fork tag should be added to the release table at the bottom.

Conventions:

- `KOKEN_VERSION` stays at upstream's `1.3.3`. Fork builds are `KOKEN_FORK_VERSION` in `app/application/config/koken.php`, tagged `1.3.3-sjp.N`. Builds 1 to 5 used the suffix `hdr`.
- Fork-only configuration goes in `storage/configuration/user_setup.php` as `define()` constants with a safe default when absent.
- Upstream files are edited in place rather than overridden, so each row names the files to diff against upstream.

## Images

| Change | Why | Commit | Files |
|---|---|---|---|
| HDR (ISO 21496-1 / Ultra HDR gain-map) output at the larger presets via `ultrahdr_app`, controlled by `ULTRAHDR_PATH` and `KOKEN_HDR_PRESETS` | Koken's resize flattened gain-map JPEGs to SDR | 9dcf99f (2026-06-18) | `app/koken/GainMap.php` (new), `app/koken/Darkroom/DarkroomUltraHDR.php` (new), `app/application/models/content.php`, `i.php`, `storage/configuration/user_setup.php` |
| Strip base-image metadata except the ICC profile before muxing the gain map | Base images carrying Exif produced corrupt HDR output | 743f351 (2026-06-18) | `app/koken/Darkroom/DarkroomUltraHDR.php` |

## Video

| Change | Why | Commit | Files |
|---|---|---|---|
| `captured_on` for videos taken from the container `creation_time`, shifted from UTC into the site timezone | Videos had no capture date and sorted apart from photos shot alongside them | 52edadc (2026-09-23) | `app/koken/ffmpeg.php` (`creation_time()`), `app/application/models/content.php` (`utc_to_site_wallclock()`) |
| Videos shorter than 4 seconds take one poster frame from the midpoint instead of running `exec('')` (superseded by the single-frame poster in sjp.9) | `create_thumbs()` produced no sample points for short clips; `exec('')` is a fatal `ValueError` on PHP 8, giving a bare 500 on upload | 96905bd (2026-10-02) | `app/koken/ffmpeg.php` |
| `moov` atom moved to the front of uploaded videos with `qt-faststart`, located next to ffmpeg or on `PATH`, overridable with `QT_FASTSTART_PATH`; no-op when the tool is missing | Phones write `moov` at the end, so playback could not start until the whole file had downloaded. `qt-faststart` is used instead of an ffmpeg remux because ffmpeg drops Apple's `vexu` box (spatial video) and refuses phone metadata tracks | 8c0faa9 (2026-10-06) | `app/koken/ffmpeg.php` (`faststart()`), `app/application/models/content.php`, `app/application/config/koken.php`, `storage/configuration/user_setup.php` |
| Video poster is the first frame of the video, written with `-y` after clearing the `_previews` folder; `tools/regen-video-posters.php` (run from anywhere with `--root`) re-picks posters for existing videos, deletes their unused frames, updates `lg_preview` and clears the image, API and site caches; `tools/.htaccess` denies web access to the tools folder | Upstream wrote up to twelve full-size frames per video, used only one, and chose it from an unsorted `readdir()` listing, so posters were effectively random; stale frames were never overwritten on replace | pending (2026-10-18) | `app/koken/ffmpeg.php` (`create_thumbs()`), `tools/regen-video-posters.php` (new), `tools/.htaccess` (new) |

## Database and API

| Change | Why | Commit | Files |
|---|---|---|---|
| `content.filesize` widened to `BIGINT` (migration 0043) | Uploads over 2 GB failed on the `INT` column | 471b63d (2026-08-31) | `app/application/models/migrations/0043.php` (new), `app/koken/schema.php` |
| 500-item cap on `/content` listings removed; default page size still 100 | The front end needed full listings; API responses are cached per URL so a large page is built once per purge | fa9a425 (2026-09-23) | `app/application/models/content.php` |
| Searching or filtering by a tag that does not exist returns an empty result instead of a 500 | Broke admin search | e429683 (2026-10-02) | `app/application/models/koken.php` |
| Renditions can be requested as `.webp`: the two URL regexes in `i.php` accept it, the Darkroom gets a `format()` setter and each driver encodes WebP on request, `WebPICC` wraps the extracted ICC profile into an `ICCP` chunk for GD output, the `.1600` midsize lookup uses the original's extension, HDR is skipped for WebP | Smaller thumbnails for the website; gain maps have no WebP container so the big presets stay JPEG | 2026-11-03 | `i.php`, `app/koken/WebPICC.php` (new), `app/koken/Darkroom/Darkroom.php`, `DarkroomGD2.php`, `DarkroomImagick.php`, `DarkroomImageMagick.php` |
| HDR driver speed-up: `-define jpeg:size` decode hint for the base AND the gain map (Adobe writes the gain map at full resolution), both resizes run in parallel, the gain map is scaled straight to the base's final size instead of being normalised to the original's resolution, `DARKROOM_MAGICK_*` limits passed to every convert call, metadata cache moved from the service's private /tmp to `storage/cache/uhdr/` | HDR renditions took 4–6 s each on hazel, dominated by decoding two 45-megapixel JPEGs at full size and a metadata decode that was repeated after every php-fpm restart | 2026-11-03 | `app/koken/Darkroom/DarkroomUltraHDR.php` |
| 2x quality floor in `adjustForRetina()` is 35 for WebP output (50 for everything else) | The admin sliders could not take a 2x WebP thumbnail below 50 | dd3af90 (2026-11-03) | `app/koken/Darkroom/Darkroom.php` |
| `fork_version` returned by the `/system` API call and shown beside the version in the admin Settings and About panels | Tell at a glance which fork build a site runs | ea1a9b5 (2026-10-02), suffix renamed in dece5cd | `app/application/config/koken.php`, `app/application/controllers/system.php`, `admin/templates/app.tmpl.html`, `admin/templates/settings.tmpl.html` |

## Tooling (not part of the served app)

| Change | Why | Commit | Files |
|---|---|---|---|
| `tools/spatial2mp4.sh`: converts Apple spatial video (MV-HEVC `.MOV`) to a web-servable `.mp4`, `-spatial` suffix by default, source creation date carried through | Koken cannot serve the `.MOV` and Safari on Vision Pro needs the re-stamped spatial metadata | b162a48, 12cfac4, 84d8eab (2026-08-31) | `tools/spatial2mp4.sh` (new) |
| `spatial2mp4.sh` gains `-b <bitrate>` re-encode through `spatial make`, `-Y` side-by-side 3D output for YouTube, and `-N` to write only that file | Smaller uploads and a YouTube-recognised 3D file | 968b401, 8f4c5be (2026-09-23) | `tools/spatial2mp4.sh` |

## Fork releases

| Tag | Date | Contents |
|---|---|---|
| 1.3.3-hdr.1 | 2026-06-18 | HDR gain-map output |
| 1.3.3-hdr.2 | 2026-06-18 | Corrupt HDR output fix |
| 1.3.3-hdr.3 | 2026-09-23 | Spatial video tool, BIGINT filesize, creation date through conversion, content listing cap removed |
| 1.3.3-hdr.4 | 2026-09-23 | Spatial tool `-b`, `-Y`, `-N`; video `captured_on` |
| 1.3.3-hdr.5 | 2026-10-02 | Fork build shown in the admin and returned by `/system` |
| 1.3.3-sjp.6 | 2026-10-02 | Short video upload fix; suffix renamed `hdr` to `sjp` |
| 1.3.3-sjp.7 | 2026-10-02 | Missing-tag filter 500 fix |
| 1.3.3-sjp.8 | 2026-10-06 | `qt-faststart` on upload |
| 1.3.3-sjp.9 | 2026-10-18 | Single-frame video posters, regen tool |
| 1.3.3-sjp.10 | 2026-11-03 | WebP renditions on request |
| 1.3.3-sjp.11 | 2026-11-03 | HDR render speed-up; WebP 2x quality floor |
