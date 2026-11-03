<?php

/**
 * Embed an ICC colour profile in a WebP blob.
 *
 * GD's imagewebp() writes a bare lossy ("VP8 ") or lossless ("VP8L") bitstream
 * with no metadata, so a Display P3 photo would be shown as sRGB. WebP carries a
 * profile in an ICCP chunk, which is only allowed in the extended ("VP8X")
 * container, so this converts a simple file to extended when needed and inserts
 * the chunk where the spec puts it: straight after VP8X. Mirrors what icc.php
 * does for JPEG (APP2 segments).
 */
class WebPICC
{
    const FLAG_ICC   = 0x20;
    const FLAG_ALPHA = 0x10;

    /**
     * @param string $webp  A complete WebP file as a string.
     * @param string $icc   Raw ICC profile bytes.
     * @return string       The WebP with the profile embedded; the input unchanged when
     *                      the profile is empty, the blob is not WebP, or it already
     *                      carries a profile.
     */
    public static function embed($webp, $icc)
    {
        if ($icc === '' || $icc === null || strlen($webp) < 20) {
            return $webp;
        }

        if (substr($webp, 0, 4) !== 'RIFF' || substr($webp, 8, 4) !== 'WEBP') {
            return $webp;
        }

        $chunks = self::chunks($webp);

        if (empty($chunks)) {
            return $webp;
        }

        foreach ($chunks as $chunk) {
            if ($chunk['id'] === 'ICCP') {
                return $webp;
            }
        }

        $iccChunk = self::chunk('ICCP', $icc);

        if ($chunks[0]['id'] === 'VP8X') {
            // Already extended: set the ICC flag and insert the profile after VP8X.
            $vp8x = $chunks[0]['data'];
            $vp8x[0] = chr(ord($vp8x[0]) | self::FLAG_ICC);
            $body = self::chunk('VP8X', $vp8x) . $iccChunk;

            for ($i = 1; $i < count($chunks); $i++) {
                $body .= $chunks[$i]['raw'];
            }
        } else {
            // Simple file: wrap the bitstream in an extended container.
            $size = self::canvasSize($chunks[0]);

            if ($size === false) {
                return $webp;
            }

            list($width, $height, $alpha) = $size;
            $flags = self::FLAG_ICC | ($alpha ? self::FLAG_ALPHA : 0);

            $vp8x = chr($flags) . "\x00\x00\x00"
                . self::le24($width - 1) . self::le24($height - 1);

            $body = self::chunk('VP8X', $vp8x) . $iccChunk;

            foreach ($chunks as $chunk) {
                $body .= $chunk['raw'];
            }
        }

        return 'RIFF' . pack('V', strlen($body) + 4) . 'WEBP' . $body;
    }

    /**
     * Split the RIFF payload into chunks.
     * @return array<int, array{id:string, data:string, raw:string}>
     */
    private static function chunks($webp)
    {
        $chunks = array();
        $offset = 12;
        $length = strlen($webp);

        while ($offset + 8 <= $length) {
            $id = substr($webp, $offset, 4);
            $size = unpack('V', substr($webp, $offset + 4, 4))[1];
            $padded = $size + ($size % 2);

            if ($offset + 8 + $size > $length) {
                return array();
            }

            $chunks[] = array(
                'id'   => $id,
                'data' => substr($webp, $offset + 8, $size),
                'raw'  => substr($webp, $offset, 8 + $padded),
            );

            $offset += 8 + $padded;
        }

        return $chunks;
    }

    /**
     * Canvas width, height and whether the bitstream has alpha, read from the
     * first bitstream chunk of a simple-format file.
     * @return array{0:int,1:int,2:bool}|false
     */
    private static function canvasSize($chunk)
    {
        $d = $chunk['data'];

        if ($chunk['id'] === 'VP8 ' && strlen($d) >= 10 && substr($d, 3, 3) === "\x9d\x01\x2a") {
            // Key frame: 3-byte frame tag, 3-byte start code, then 16-bit LE
            // width and height whose top two bits are scaling fields.
            $width = unpack('v', substr($d, 6, 2))[1] & 0x3fff;
            $height = unpack('v', substr($d, 8, 2))[1] & 0x3fff;

            return array($width, $height, false);
        }

        if ($chunk['id'] === 'VP8L' && strlen($d) >= 5 && $d[0] === "\x2f") {
            // Signature byte, then 14 bits width-1, 14 bits height-1, 1 bit alpha.
            $bits = unpack('V', substr($d, 1, 4))[1];
            $width = ($bits & 0x3fff) + 1;
            $height = (($bits >> 14) & 0x3fff) + 1;
            $alpha = (bool) (($bits >> 28) & 1);

            return array($width, $height, $alpha);
        }

        return false;
    }

    private static function chunk($id, $data)
    {
        $size = strlen($data);

        return $id . pack('V', $size) . $data . ($size % 2 ? "\x00" : '');
    }

    private static function le24($n)
    {
        return chr($n & 0xff) . chr(($n >> 8) & 0xff) . chr(($n >> 16) & 0xff);
    }
}
