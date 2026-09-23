#!/usr/bin/env bash
#
# spatial2mp4 - convert an Apple MV-HEVC spatial video (.MOV) into a .mp4 that
# Koken can serve and Safari on Vision Pro will present in stereo.
#
# WHY THIS EXISTS
#
# Koken serves originals byte-for-byte (no transcode), which is what makes
# spatial video viable at all - a re-encode would flatten the stereo pair to
# mono. So by default this is a container swap only; the MV-HEVC bitstream,
# including the second-eye dependent layer, is copied verbatim.
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
# OPTIONAL RE-ENCODE (-b)
#
# iPhone spatial originals run at ~25 Mbps for 1080p, so a few minutes is well
# over a gigabyte. Nothing in ffmpeg can encode MV-HEVC, but `spatial make`
# can, via VideoToolbox. With -b the pipeline becomes:
#   export both eyes to a near-lossless side-by-side HEVC intermediate,
#   re-encode that back to MV-HEVC at the requested bitrate with the source's
#   own geometry, then remux exactly as above (audio still comes from the
#   original, untouched).
# Measured on an iPhone 17 Pro 1080p30 clip against the original: 8M scores
# VMAF ~95 (visually transparent) at ~1/3 the size; 12M ~98; 6M ~90 with
# visible softening in the worst frames. Apple Silicon only.
#
# OPTIONAL YOUTUBE 3D OUTPUT (-Y)
#
# YouTube wants a plain side-by-side (left eye on the left) file with an st3d
# box. `spatial export --add-st3d` produces exactly that; it must be a .mov
# because the tool refuses to copy audio into .mp4. ffmpeg would drop st3d on
# a remux, so this file is never passed through ffmpeg.
#
# Requires: ffmpeg (>= 7.1, for MV-HEVC) and `spatial` (https://blog.mikeswanson.com)
# Usage:    tools/spatial2mp4.sh [-b <bitrate>] [-Y [-N]] <input.MOV> [output.mp4]

set -euo pipefail

usage() {
  cat >&2 <<EOF
usage: $(basename "$0") [-b <bitrate>] [-Y [-N]] <input.MOV> [output.mp4]
  -b <bitrate>  re-encode both eyes at this video bitrate (e.g. 8M) instead of
                copying the original bitstream. 8M is transparent for 1080p30.
  -Y            also write <stem>-sbs3d.mov: full-width side-by-side with st3d
                metadata, for uploading to YouTube as 3D.
  -N            with -Y: write only the YouTube file, skip the spatial .mp4.
EOF
  exit 1
}

BITRATE=
YOUTUBE=
YTONLY=
while getopts ":b:YN" opt; do
  case "$opt" in
    b) BITRATE=$OPTARG ;;
    Y) YOUTUBE=1 ;;
    N) YTONLY=1 ;;
    *) usage ;;
  esac
done
shift $((OPTIND - 1))
[ $# -ge 1 ] || usage
if [ -n "$YTONLY" ]; then
  [ -n "$YOUTUBE" ] || { echo "error: -N only makes sense together with -Y" >&2; usage; }
  [ -z "$BITRATE" ] || echo "warn: -b ignored with -N; the YouTube file always comes from the original" >&2
fi

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

# The YouTube file sits next to the main output, without the -spatial suffix
# (it is not MV-HEVC and must not be picked up by the site as spatial).
ytstem=${OUT%.*}
ytstem=${ytstem%-spatial}
ytstem=${ytstem%_spatial}
YTOUT="${ytstem}-sbs3d.mov"

TMPBASE=$(mktemp -t spatial2mp4)
TMP="$TMPBASE-remux.mp4"
SBS="$TMPBASE-sbs.mov"
ENC="$TMPBASE-enc.mov"
trap 'rm -f "$TMPBASE" "$TMP" "$SBS" "$ENC"' EXIT

for t in ffmpeg ffprobe spatial; do
  command -v "$t" >/dev/null || { echo "error: '$t' not found in PATH" >&2; exit 1; }
done
[ -f "$IN" ] || { echo "error: no such file: $IN" >&2; exit 1; }

# 1. Capture the source's spatial metadata. These values are per-device and
#    per-lens (baseline, FOV, disparity), so they must be read from THIS file.
META=$(spatial metadata -i "$IN" 2>/dev/null | sed -n 's/^[[:space:]]*\(vexu:.*\)/\1/p' || true)
[ -n "$META" ] || { echo "error: no vexu metadata - '$IN' is not a spatial video" >&2; exit 1; }
SET=$(printf '%s\n' "$META" | sed -E 's/[[:space:]]*=[[:space:]]*/=/' | paste -sd, -)

# Match the source's filesystem timestamps on an output. touch covers mtime;
# the macOS creation date needs SetFile, which is absent on a bare system.
stamp() {
  touch -r "$IN" "$1"
  if command -v SetFile >/dev/null 2>&1; then
    SetFile -d "$(stat -f '%SB' -t '%m/%d/%Y %H:%M:%S' "$IN")" "$1" 2>/dev/null || true
  fi
}

# Steps 2-6 produce the spatial .mp4; -N skips them entirely.
spatial_mp4() {
  # 2. Pick the AAC track. iPhone spatial videos also carry an Apple positional
  #    audio (apac) track and mebx metadata tracks; neither is web-playable.
  AAC=$(ffprobe -v error -select_streams a -show_entries stream=index,codec_name \
          -of csv=p=0 "$IN" | awk -F, '$2=="aac"{print $1; exit}')
  if [ -n "$AAC" ]; then MAPA=(-map "1:$AAC"); else MAPA=(); echo "warn: no AAC track; output will be silent" >&2; fi

  # 2b. ffmpeg drops creation_time on a stream copy, so read it now and hand it
  #     back in below. Without this every conversion is stamped with the date it
  #     was converted rather than the date it was shot.
  CREATED=$(ffprobe -v error -show_entries format_tags=creation_time \
              -of default=nw=1:nk=1 "$IN" 2>/dev/null || true)
  if [ -n "$CREATED" ]; then CTIME=(-metadata "creation_time=$CREATED"); else CTIME=(); fi

  # 2c. Optional re-encode. The video source for the remux is either the original
  #     (bitstream copy) or a fresh MV-HEVC encode at the requested bitrate.
  VSRC=$IN
  if [ -n "$BITRATE" ]; then
    # Geometry for `spatial make`, taken from the vexu values captured above and
    # the primary eye reported by `spatial info`. Without these the result plays
    # as flat 2D in Photos and Files.
    geo() { printf '%s\n' "$META" | awk -F'=' -v k="vexu:$1" '$1 ~ k"[[:space:]]*$" {gsub(/[[:space:]]/, "", $2); print $2; exit}'; }
    CDIST=$(geo cameraBaseline)
    HFOV=$(geo horizontalFieldOfView)
    HADJ=$(geo horizontalDisparityAdjustment)
    case "$(geo projectionKind)" in
      rectilinear | "") PROJ=rect ;;
      equirectangular)  PROJ=equirect ;;
      halfEquirectangular) PROJ=halfEquirect ;;
      fisheye)          PROJ=fisheye ;;
      *) echo "error: unsupported projection '$(geo projectionKind)'" >&2; exit 1 ;;
    esac
    [ -n "$CDIST" ] && [ -n "$HFOV" ] || { echo "error: source lacks baseline/FOV, cannot re-encode" >&2; exit 1; }
    GEO=(--cdist "$CDIST" --hfov "$HFOV" --hadjust "${HADJ:-0}" --projection "$PROJ")
    PRIMARY=$(spatial info -i "$IN" 2>/dev/null | sed -n 's/^Primary eye:[[:space:]]*//p' | head -1)
    case "$PRIMARY" in left | right) GEO+=(--primary "$PRIMARY") ;; esac

    # Near-lossless side-by-side intermediate (~85 Mbps for 1080p, so ~5 GB for
    # 8 minutes in \$TMPDIR). ProRes would be transparent but 5x larger, and the
    # difference in the final encode measured under one VMAF point.
    echo "re-encoding at $BITRATE (export)..." >&2
    spatial export -i "$IN" -o "$SBS" -f sbs -v hevc -q 0.9 -n -y >/dev/null 2>&1 \
      || { echo "error: spatial export failed" >&2; exit 1; }
    echo "re-encoding at $BITRATE (make)..." >&2
    spatial make -i "$SBS" -f sbs -o "$ENC" -b "$BITRATE" "${GEO[@]}" -n -y >/dev/null 2>&1 \
      || { echo "error: spatial make failed" >&2; exit 1; }
    rm -f "$SBS"
    VSRC=$ENC
  fi

  # 3. Remux. Video from VSRC (copied verbatim - either the original MV-HEVC or
  #    the re-encode), audio always from the original.
  ffmpeg -y -hide_banner -loglevel error \
    -i "$VSRC" -i "$IN" -map 0:v:0 "${MAPA[@]}" \
    -c copy -tag:v hvc1 -movflags +faststart "${CTIME[@]}" "$TMP"

  # 4. Re-stamp the spatial metadata ffmpeg dropped.
  spatial metadata -i "$TMP" -o "$OUT" -y --set "$SET" >/dev/null

  # 5. Match the source's filesystem timestamps.
  stamp "$OUT"

  # 6. Verify rather than assume.
  if ! spatial metadata -i "$OUT" 2>/dev/null | grep -q 'hasRightEyeView[[:space:]]*=[[:space:]]*true'; then
    echo "error: verification failed - right eye not declared in $OUT" >&2; exit 1
  fi
  echo "ok: $OUT"
  spatial info -i "$OUT" | sed -n '/Spatial Properties/,$p'
}
[ -n "$YTONLY" ] || spatial_mp4

# 7. Optional YouTube 3D file: full-width side-by-side HEVC with an st3d box,
#    straight from the original so both eyes are first-generation. YouTube
#    re-encodes on ingest, so a generous fixed bitrate is fine.
if [ -n "$YOUTUBE" ]; then
  echo "writing YouTube 3D side-by-side..." >&2
  spatial export -i "$IN" -o "$YTOUT" -f sbs -v hevc -b 40M --add-st3d -y >/dev/null 2>&1 \
    || { echo "error: spatial export (YouTube) failed" >&2; exit 1; }
  stamp "$YTOUT"
  if ! ffprobe -v error -select_streams v:0 -show_entries stream_side_data_list -of compact "$YTOUT" 2>/dev/null \
       | grep -q 'stereo_3d:type=side by side'; then
    echo "error: verification failed - no st3d side-by-side flag in $YTOUT" >&2; exit 1
  fi
  echo "ok: $YTOUT"
fi
