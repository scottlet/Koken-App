# Koken

## About this fork

This is a fork of [modufolio/Koken-App](https://github.com/modufolio/Koken-App) with a few additions on top. Everything below this section is the original README.

**Current release: [1.3.3-hdr.4](https://github.com/scottlet/Koken-App/releases/tag/1.3.3-hdr.4)**, based on upstream Koken 1.3.3. `KOKEN_VERSION` stays at 1.3.3; fork builds are tagged `1.3.3-hdr.N`. When following the setup or update instructions below, download the zip for this fork's tag from https://github.com/scottlet/Koken-App/tags instead of the upstream one.

| Tag | What changed |
|---|---|
| 1.3.3-hdr.1 | HDR gain-map output via libultrahdr |
| 1.3.3-hdr.2 | Fix corrupt HDR output when the base image carried Exif |
| 1.3.3-hdr.3 | Spatial video tool, BIGINT filesize, creation date carried through conversion, 500-item content listing cap removed |
| 1.3.3-hdr.4 | Spatial tool gains `-b` re-encode and `-Y`/`-N` YouTube 3D output; videos get `captured_on` from their creation date |

### HDR (gain-map) images

Uploaded JPEGs that carry an ISO 21496-1 / Ultra HDR gain map (iPhone, Pixel, Lightroom exports) are served as HDR at the larger size presets instead of being flattened to SDR when Koken resizes them. Resizing goes through [libultrahdr](https://github.com/google/libultrahdr), which scales the base image and the gain map together and re-muxes them, so the derivative is still a plain `.jpg` and nothing downstream changes. Smaller presets stay SDR using the gain map's built-in fallback.

To enable it, install `ultrahdr_app` and set in `storage/configuration/user_setup.php`:

```php
define('ULTRAHDR_PATH', '/usr/local/bin/ultrahdr_app');
// optional, defaults to xlarge,huge
define('KOKEN_HDR_PRESETS', 'xlarge,huge');
```

The original's creation date is carried through the conversion.

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
