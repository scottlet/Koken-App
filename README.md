# Koken

## About this fork

This is a fork of [modufolio/Koken-App](https://github.com/modufolio/Koken-App) with a few additions on top. Everything below this section is the original README.

**Current release: [1.3.3-sjp.10](https://github.com/scottlet/Koken-App/releases/tag/1.3.3-sjp.10)**, based on upstream Koken 1.3.3. `KOKEN_VERSION` stays at 1.3.3; fork builds are tagged `1.3.3-sjp.N`. Builds 1 to 5 used an `hdr` suffix, from when the fork was only about HDR; the numbering carries on unbroken. When following the setup or update instructions below, download the zip for this fork's tag from https://github.com/scottlet/Koken-App/tags instead of the upstream one.

| Tag | What changed |
|---|---|
| 1.3.3-hdr.1 | HDR gain-map output via libultrahdr |
| 1.3.3-hdr.2 | Fix corrupt HDR output when the base image carried Exif |
| 1.3.3-hdr.3 | Spatial video tool, BIGINT filesize, creation date carried through conversion, 500-item content listing cap removed |
| 1.3.3-hdr.4 | Spatial tool gains `-b` re-encode and `-Y`/`-N` YouTube 3D output; videos get `captured_on` from their creation date |
| 1.3.3-hdr.5 | Fork build shown beside the version in the admin and returned by `/system` |
| 1.3.3-sjp.6 | Videos shorter than 4 seconds no longer fail on upload; tag suffix changed from `hdr` to `sjp` |
| 1.3.3-sjp.7 | Searching or filtering by a tag that does not exist returns no results instead of a 500, which fixes admin search |
| 1.3.3-sjp.8 | Uploaded videos get `moov` moved to the front with `qt-faststart`, spatial-safe |
| 1.3.3-sjp.9 | Video posters are the first frame of the video, one file per upload, instead of up to twelve unused frames and a random pick; `tools/regen-video-posters.php` fixes existing videos |
| 1.3.3-sjp.10 | Any rendition can be requested as WebP by asking for `.webp` instead of the original's extension; the colour profile is kept, HDR presets stay JPEG |

The complete list of changes relative to upstream, with commits and files, is kept in [docs/DEVIATIONS.md](docs/DEVIATIONS.md).

The admin shows the fork build beside the version, for example "1.3.3 (sjp.9)", under Settings and in the About panel. It comes from `KOKEN_FORK_VERSION` in `app/application/config/koken.php`, which is bumped with each fork tag, and is also returned as `fork_version` by the `/system` API call.

### HDR (gain-map) images

Uploaded JPEGs that carry an ISO 21496-1 / Ultra HDR gain map (iPhone, Pixel, Lightroom exports) are served as HDR at the larger size presets instead of being flattened to SDR when Koken resizes them. Resizing goes through [libultrahdr](https://github.com/google/libultrahdr), which scales the base image and the gain map together and re-muxes them, so the derivative is still a plain `.jpg` and nothing downstream changes. Smaller presets stay SDR using the gain map's built-in fallback.

To enable it, install `ultrahdr_app` and set in `storage/configuration/user_setup.php`:

```php
define('ULTRAHDR_PATH', '/usr/local/bin/ultrahdr_app');
// optional, defaults to xlarge,huge
define('KOKEN_HDR_PRESETS', 'xlarge,huge');
```

The original's creation date is carried through the conversion.

### WebP renditions

Request any cached rendition with `.webp` in place of the original's extension, for example `411A7743,small.crop.2x.1793553624.webp` next to the `.jpg` the API reports, and Koken resizes and encodes it as WebP instead. The original and every existing URL are untouched, so this is purely additive: a front end can offer WebP in a `srcset` and keep the JPEG as `src`. The ICC profile survives with every driver (GD writes none of its own, so the profile Koken already extracts for JPEG is wrapped into an `ICCP` chunk; Imagick and `convert` keep it through the strip). HDR output is JPEG only, because WebP has no gain-map container, so a `.webp` request for an HDR-eligible preset gets the SDR rendition. Quality and sharpening follow the preset settings as for JPEG. Needs GD built with WebP (`imagewebp`), or an ImageMagick with the WebP delegate.

### Spatial video (MV-HEVC)

`tools/spatial2mp4.sh` converts an Apple spatial video `.MOV` into an `.mp4` that Koken can serve and Safari on Apple Vision Pro will play in stereo. It is a container swap only, so the stereo bitstream is copied verbatim, the Apple spatial metadata (`vexu` and friends) is re-stamped, `moov` is moved to the front for streaming, and the non-web audio and metadata tracks are dropped. Output is named with a `-spatial` suffix by default.

```
tools/spatial2mp4.sh [-b <bitrate>] [-Y [-N]] <input.MOV> [output.mp4]
```

- `-b <bitrate>` re-encodes both eyes at that video bitrate through `spatial make` instead of copying the original bitstream. iPhone originals run at about 25 Mbps for 1080p. Measured against the original, `8M` scores VMAF 95 (visually transparent) at roughly a third of the size, `12M` scores 98, and `6M` drops to 90 with visible softening in the worst frames. Audio is still copied from the original. Apple Silicon only.
- `-Y` also writes `<stem>-sbs3d.mov`, a full-width side-by-side file with an `st3d` box, which YouTube recognises as 3D on upload. Add `-N` to write only that file and skip the spatial mp4.

Requires ffmpeg 7.1 or later and the [`spatial`](https://blog.mikeswanson.com) CLI.

### Other changes

- `content.filesize` is now a `BIGINT`, so uploads over 2 GB no longer fail (migration 0043).
- Uploaded videos get their `moov` atom moved to the front with `qt-faststart`, so playback can start before the whole file has downloaded. Phones write it at the end. `qt-faststart` ships with ffmpeg and is looked for next to the ffmpeg binary or on `PATH`; set `QT_FASTSTART_PATH` in `user_setup.php` if it lives elsewhere. Uploads are left untouched if it is missing. Unlike an ffmpeg remux, this preserves spatial video and phone metadata tracks byte for byte.
- Videos shorter than 4 seconds no longer fail on upload, and every video gets its shot date as `captured_on`.
- Video posters are the first frame of the video, a single file per upload. Upstream extracted up to twelve full-size frames per video, kept them all, and picked one from an unsorted directory listing, so the poster was effectively random. Run `tools/regen-video-posters.php` once to redo the poster for existing videos and delete their unused frames: copy that one file anywhere on the server, then as the owner of `storage/` run it with `--root=/path/to/koken --dry-run` first and without `--dry-run` to apply.
- `tools/` is not part of the site and does not need to be deployed. If it is, `tools/.htaccess` denies all web requests to it on Apache; on nginx add an equivalent `location /tools/ { deny all; }`.
- The 500-item cap on `/content` API listings has been removed. The default page size is still 100 when no `limit` is passed. API responses are cached per URL, so a large page is only built once per cache purge.

---

The purpose of this repo is to keep koken running on our machines/hosting providers.

Sadly, it appears that the Koken project has been abandoned by its owner.

Read more about it on: https://www.koken.me/ by the Co-Founder of Koken - Todd Dominey

Let me know if you encounter some bugs with installing or updating koken.

### Setup instructions

1. download the zip with the tag 1.3.3 / unpack

2. Go to the url wwww.yourdomain.com/install.html

3. Fill the correct data to connect with the database (there are currently no system or database checks for this early version of the installer)

4. Follow the steps, enjoy.


 
### Update instructions

1. Remove all the files except the storage folder.

2. Download the lasted zip https://github.com/modufolio/Koken-App/tags

3. Unpack the zip

4. Copy all the folders / files except the storage folder.

5. Remove all the files in the storage/cache/api folder.

It should work now.
