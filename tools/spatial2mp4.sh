#!/usr/bin/env bash
#
# spatial2mp4 - convert an Apple MV-HEVC spatial video (.MOV) into a .mp4 that
# Koken can serve and Safari on Vision Pro will present in stereo.
#
# WHY THIS EXISTS
#
# Koken serves originals byte-for-byte (no transcode), which is what makes
# spatial video viable at all - a re-encode would flatten the stereo pair to
# mono. So this is a container swap only; the MV-HEVC bitstream, including the
# second-eye dependent layer, is copied verbatim.
#
# The trap: `ffmpeg -c copy` alone silently breaks spatial playback. It keeps
# both layers but its mp4 muxer cannot write Apple's vexu box (there is no
# -movflags for it), so every spatial tag - vexu, eyes, stri, blin, cams, cmfy,
# hfov, proj, prji - is dropped. The result decodes and plays fine, as MONO.
# Hence: capture the metadata first, remux, then re-stamp it with `spatial`.
#
# Two other things this fixes:
#   - iPhone originals are not faststart (moov sits at the END of the file), so
#     served statically the browser must pull the whole thing before playback
#     starts. Output puts moov at the front.
#   - iPhone spatial videos carry an Apple positional audio (apac) track and
#     mebx metadata tracks. Neither is web-playable, so only AAC is kept.
#
# The vexu values (baseline, FOV, disparity) are per-device and per-lens and are
# therefore read from each input - never hardcoded.
#
# Requires: ffmpeg (>= 7.1, for MV-HEVC) and `spatial` (https://blog.mikeswanson.com)
# Usage:    tools/spatial2mp4.sh <input.MOV> [output.mp4]

set -euo pipefail

usage() { echo "usage: $(basename "$0") <input.MOV> [output.mp4]" >&2; exit 1; }
[ $# -ge 1 ] || usage

IN=$1

# The site detects spatial clips by filename (/-spatial\.mp4$/i), so the
# default output carries that suffix. Koken slugifies on upload but keeps it:
# IMG_5152-spatial.mp4 is stored as IMG-5152-spatial.mp4, which still matches.
if [ $# -ge 2 ]; then
  OUT=$2
else
  stem=${IN%.*}
  case "$stem" in
    *-spatial | *_spatial) OUT="${stem}.mp4" ;;
    *) OUT="${stem}-spatial.mp4" ;;
  esac
fi

case "$OUT" in
  *-spatial.mp4 | *_spatial.mp4) ;;
  *) echo "warn: '$OUT' lacks a -spatial suffix; the site will treat it as a regular video" >&2 ;;
esac
TMP=$(mktemp -t spatial2mp4)-remux.mp4
trap 'rm -f "$TMP"' EXIT

for t in ffmpeg ffprobe spatial; do
  command -v "$t" >/dev/null || { echo "error: '$t' not found in PATH" >&2; exit 1; }
done
[ -f "$IN" ] || { echo "error: no such file: $IN" >&2; exit 1; }

# 1. Capture the source's spatial metadata. These values are per-device and
#    per-lens (baseline, FOV, disparity), so they must be read from THIS file.
META=$(spatial metadata -i "$IN" 2>/dev/null | sed -n 's/^[[:space:]]*\(vexu:.*\)/\1/p' || true)
[ -n "$META" ] || { echo "error: no vexu metadata - '$IN' is not a spatial video" >&2; exit 1; }
SET=$(printf '%s\n' "$META" | sed -E 's/[[:space:]]*=[[:space:]]*/=/' | paste -sd, -)

# 2. Pick the AAC track. iPhone spatial videos also carry an Apple positional
#    audio (apac) track and mebx metadata tracks; neither is web-playable.
AAC=$(ffprobe -v error -select_streams a -show_entries stream=index,codec_name \
        -of csv=p=0 "$IN" | awk -F, '$2=="aac"{print $1; exit}')
if [ -n "$AAC" ]; then MAPA=(-map "0:$AAC"); else MAPA=(); echo "warn: no AAC track; output will be silent" >&2; fi

# 2b. ffmpeg drops creation_time on a stream copy, so read it now and hand it
#     back in below. Without this every conversion is stamped with the date it
#     was converted rather than the date it was shot.
CREATED=$(ffprobe -v error -show_entries format_tags=creation_time \
            -of default=nw=1:nk=1 "$IN" 2>/dev/null || true)
if [ -n "$CREATED" ]; then CTIME=(-metadata "creation_time=$CREATED"); else CTIME=(); fi

# 3. Container swap only - the MV-HEVC bitstream (both layers) is copied verbatim.
ffmpeg -y -hide_banner -loglevel error \
  -i "$IN" -map 0:v:0 "${MAPA[@]}" \
  -c copy -tag:v hvc1 -movflags +faststart "${CTIME[@]}" "$TMP"

# 4. Re-stamp the spatial metadata ffmpeg dropped.
spatial metadata -i "$TMP" -o "$OUT" -y --set "$SET" >/dev/null

# 5. Match the source's filesystem timestamps. touch covers mtime; the macOS
#    creation date needs SetFile, which is absent on a bare system.
touch -r "$IN" "$OUT"
if command -v SetFile >/dev/null 2>&1; then
  SetFile -d "$(stat -f '%SB' -t '%m/%d/%Y %H:%M:%S' "$IN")" "$OUT" 2>/dev/null || true
fi

# 6. Verify rather than assume.
if ! spatial metadata -i "$OUT" 2>/dev/null | grep -q 'hasRightEyeView[[:space:]]*=[[:space:]]*true'; then
  echo "error: verification failed - right eye not declared in $OUT" >&2; exit 1
fi
echo "ok: $OUT"
spatial info -i "$OUT" | sed -n '/Spatial Properties/,$p'
