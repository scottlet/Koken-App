<?php

/**
 * GainMap — detects whether a JPEG carries an HDR gain map.
 *
 * A gain map is what makes an image render as HDR on capable displays
 * (iPhone/Safari/Chrome) while staying a normal SDR JPEG everywhere else.
 * This is purely additive to Koken: it parses the JPEG's header (APPn)
 * segments — in the same hand-rolled style as icc.php — and looks for any
 * of the gain-map signalling conventions in the wild:
 *
 *   - ISO 21496-1 (the 2024 standard; libultrahdr/Apple/Adobe default now):
 *     an APP2 box carrying the URN "urn:iso:std:iso:ts:21496:-1".
 *   - Google/Adobe "Ultra HDR" legacy: XMP with the "hdrgm:" namespace, or a
 *     GContainer directory item with Semantic="GainMap".
 *   - Apple legacy: "apple_desktop:HDRGainMap" / the aux image URN
 *     "...aux:hdrgainmap".
 *
 * Only header segments (up to SOS) are scanned, so this is cheap even on
 * large originals — the gain-map markers always live in the primary image's
 * header, never in the appended secondary image.
 */
class GainMap
{
    // Tokens that, if found in any APP1/APP2 header segment, indicate a gain map.
    // Matched case-insensitively against the raw segment bytes.
    private static $tokens = array(
        'urn:iso:std:iso:ts:21496',   // ISO 21496-1 (current standard)
        'hdrgm:',                     // Adobe/Google Ultra HDR XMP namespace
        'item:semantic="gainmap"',    // GContainer directory (Ultra HDR / ISO container)
        'apple_desktop:hdrgainmap',   // Apple legacy XMP
        'aux:hdrgainmap',             // Apple auxiliary-image URN
    );

    /**
     * @return bool true if $path is a JPEG carrying an HDR gain map.
     */
    public static function detect($path)
    {
        $info = self::info($path);
        return $info['has_gain_map'];
    }

    /**
     * Inspect a JPEG and report what gain-map signals it carries.
     *
     * @return array{has_gain_map:bool, iso21496:bool, mpf_images:int, markers:string[]}
     */
    public static function info($path)
    {
        $result = array(
            'has_gain_map' => false,
            'iso21496'     => false,
            'mpf_images'   => 0,
            'markers'      => array(),
        );

        $fh = @fopen($path, 'rb');
        if (!$fh) {
            return $result;
        }

        // Must start with SOI (FFD8) to be a JPEG.
        if (fread($fh, 2) !== "\xFF\xD8") {
            fclose($fh);
            return $result;
        }

        while (!feof($fh)) {
            // Find the next marker (skip any fill bytes).
            $byte = fread($fh, 1);
            if ($byte === '' || $byte === false) {
                break;
            }
            if ($byte !== "\xFF") {
                continue;
            }
            // Consume run of 0xFF fill bytes; the last one precedes the marker code.
            do {
                $marker = fread($fh, 1);
            } while ($marker === "\xFF");

            if ($marker === '' || $marker === false) {
                break;
            }

            $code = ord($marker);

            // Standalone markers with no length payload.
            if ($code === 0xD8 || $code === 0xD9 || ($code >= 0xD0 && $code <= 0xD7) || $code === 0x01) {
                continue;
            }

            // SOS (0xDA) — image data begins; gain-map markers (if any) are in the header above.
            if ($code === 0xDA) {
                break;
            }

            // Every other marker carries a 2-byte big-endian length (incl. the 2 length bytes).
            $lenBytes = fread($fh, 2);
            if (strlen($lenBytes) < 2) {
                break;
            }
            $length = (ord($lenBytes[0]) << 8) + ord($lenBytes[1]) - 2;
            if ($length <= 0) {
                continue;
            }

            // Only the APPn segments (0xE0–0xEF) can hold gain-map metadata.
            if ($code >= 0xE0 && $code <= 0xEF) {
                $segment = self::freadAll($fh, $length);

                // MPF (APP2 "MPF\0…") — count embedded images; >=2 means a secondary
                // image is present (the gain map for MPF-based Ultra HDR).
                if ($code === 0xE2 && strncmp($segment, "MPF\x00", 4) === 0) {
                    $count = self::mpfImageCount($segment);
                    if ($count > $result['mpf_images']) {
                        $result['mpf_images'] = $count;
                    }
                }

                $haystack = strtolower($segment);
                foreach (self::$tokens as $token) {
                    if (strpos($haystack, $token) !== false) {
                        $result['markers'][] = $token;
                        if ($token === 'urn:iso:std:iso:ts:21496') {
                            $result['iso21496'] = true;
                        }
                    }
                }
            } else {
                // Skip non-APP segment payloads.
                fseek($fh, $length, SEEK_CUR);
            }
        }

        fclose($fh);

        $result['markers'] = array_values(array_unique($result['markers']));
        $result['has_gain_map'] = !empty($result['markers']);

        return $result;
    }

    /**
     * Extract the embedded gain-map image (the MPF secondary image) as raw JPEG
     * bytes — in pure PHP, so no exiftool dependency is needed on the server.
     *
     * Walks the JPEG looking for the APP2 "MPF\0" index, parses the MP Entry
     * list (tag 0xB002), and slices the second individual image out of the file
     * using its CIPA-spec data offset (relative to the MP Endian field) + size.
     *
     * @return string|false the gain-map JPEG bytes, or false if none found.
     */
    public static function extractGainMap($path)
    {
        $data = @file_get_contents($path);
        if ($data === false || strncmp($data, "\xFF\xD8", 2) !== 0) {
            return false;
        }

        $len = strlen($data);
        $pos = 2;

        while ($pos + 4 <= $len) {
            if ($data[$pos] !== "\xFF") {
                $pos++;
                continue;
            }
            $code = ord($data[$pos + 1]);

            if ($code === 0xD8 || $code === 0xD9 || ($code >= 0xD0 && $code <= 0xD7) || $code === 0x01) {
                $pos += 2;
                continue;
            }
            if ($code === 0xDA) {
                break; // start of scan — MPF index sits above this
            }

            $segLen = (ord($data[$pos + 2]) << 8) + ord($data[$pos + 3]);
            $payloadStart = $pos + 4;
            $payloadLen = $segLen - 2;

            if ($code === 0xE2 && $payloadLen > 4 && substr($data, $payloadStart, 4) === "MPF\x00") {
                // TIFF header begins right after "MPF\0" — all MPF offsets are
                // measured from this byte (the MP Endian field).
                $tiff = $payloadStart + 4;
                $gainmap = self::sliceMpfSecondary($data, $tiff);
                if ($gainmap !== false) {
                    return $gainmap;
                }
            }

            $pos = $payloadStart + $payloadLen;
        }

        return false;
    }

    private static function sliceMpfSecondary($data, $tiff)
    {
        $align = substr($data, $tiff, 2);
        if ($align === 'II') {
            $le = true;
        } elseif ($align === 'MM') {
            $le = false;
        } else {
            return false;
        }

        $ifdOffset = self::u32(substr($data, $tiff + 4, 4), $le);
        $ifdPos = $tiff + $ifdOffset;
        if ($ifdPos + 2 > strlen($data)) {
            return false;
        }

        $entryCount = self::u16(substr($data, $ifdPos, 2), $le);
        $entryPos = $ifdPos + 2;

        $numImages = 0;
        $mpEntryOffset = false;

        for ($i = 0; $i < $entryCount; $i++) {
            $entry = substr($data, $entryPos + ($i * 12), 12);
            if (strlen($entry) < 12) {
                break;
            }
            $tag = self::u16(substr($entry, 0, 2), $le);
            if ($tag === 0xB001) {            // NumberOfImages
                $numImages = self::u32(substr($entry, 8, 4), $le);
            } elseif ($tag === 0xB002) {      // MP Entry list (offset to 16-byte records)
                $mpEntryOffset = self::u32(substr($entry, 8, 4), $le);
            }
        }

        if ($numImages < 2 || $mpEntryOffset === false) {
            return false;
        }

        // Second individual image (index 1) is the gain map. Each MP Entry is
        // 16 bytes: [attr:4][size:4][dataOffset:4][dep1:2][dep2:2].
        $rec = $tiff + $mpEntryOffset + (1 * 16);
        $size = self::u32(substr($data, $rec + 4, 4), $le);
        $dataOffset = self::u32(substr($data, $rec + 8, 4), $le);
        if ($size <= 0) {
            return false;
        }

        // Data offset is relative to the MP Endian field (the TIFF header start).
        $start = $tiff + $dataOffset;
        $bytes = substr($data, $start, $size);

        // Sanity: the secondary image must itself be a JPEG.
        if (strncmp($bytes, "\xFF\xD8", 2) !== 0) {
            return false;
        }

        return $bytes;
    }

    /**
     * Parse the MPF (APP2) index to count the number of embedded images.
     * The segment begins with "MPF\0" followed by a TIFF header and IFD.
     */
    private static function mpfImageCount($segment)
    {
        $tiff = substr($segment, 4); // strip "MPF\0"
        if (strlen($tiff) < 8) {
            return 0;
        }

        $align = substr($tiff, 0, 2);
        if ($align === 'II') {
            $le = true;
        } elseif ($align === 'MM') {
            $le = false;
        } else {
            return 0;
        }

        $ifdOffset = self::u32(substr($tiff, 4, 4), $le);
        if ($ifdOffset + 2 > strlen($tiff)) {
            return 0;
        }

        $entryCount = self::u16(substr($tiff, $ifdOffset, 2), $le);
        $pos = $ifdOffset + 2;

        for ($i = 0; $i < $entryCount; $i++) {
            $entry = substr($tiff, $pos, 12);
            if (strlen($entry) < 12) {
                break;
            }
            $tag = self::u16(substr($entry, 0, 2), $le);
            // 0xB001 = NumberOfImages (value sits in the 4-byte value field).
            if ($tag === 0xB001) {
                return self::u32(substr($entry, 8, 4), $le);
            }
            $pos += 12;
        }

        return 0;
    }

    private static function freadAll($fh, $length)
    {
        $buf = '';
        while ($length > 0 && !feof($fh)) {
            $chunk = fread($fh, $length);
            if ($chunk === '' || $chunk === false) {
                break;
            }
            $buf .= $chunk;
            $length -= strlen($chunk);
        }
        return $buf;
    }

    private static function u16($bytes, $le)
    {
        if (strlen($bytes) < 2) {
            return 0;
        }
        return $le
            ? (ord($bytes[0]) | (ord($bytes[1]) << 8))
            : ((ord($bytes[0]) << 8) | ord($bytes[1]));
    }

    private static function u32($bytes, $le)
    {
        if (strlen($bytes) < 4) {
            return 0;
        }
        return $le
            ? (ord($bytes[0]) | (ord($bytes[1]) << 8) | (ord($bytes[2]) << 16) | (ord($bytes[3]) << 24))
            : ((ord($bytes[0]) << 24) | (ord($bytes[1]) << 16) | (ord($bytes[2]) << 8) | ord($bytes[3]));
    }
}
