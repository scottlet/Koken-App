<?php

require_once dirname(__DIR__) . '/GainMap.php';

/**
 * DarkroomUltraHDR — resizes/crops HDR (gain-map) JPEGs without destroying the
 * gain map, so derivatives keep displaying as HDR on capable devices.
 *
 * The stock Imagick/ImageMagick/GD drivers read only a JPEG's primary image, so
 * any resize drops the embedded gain map and the result is plain SDR. This driver
 * instead splits the Ultra HDR file into its parts, resizes each, and re-muxes:
 *
 *   1. base   = primary SDR image, resized/cropped with ImageMagick (keeps ICC).
 *   2. gainmap = the MPF secondary image (extracted in pure PHP by GainMap),
 *                resized/cropped to exactly the base's final size.
 *   3. meta   = the gain-map metadata (maxContentBoost, gamma, …), pulled once
 *               via `ultrahdr_app -m 1` and cached (it is resolution-independent).
 *   4. mux    = `ultrahdr_app -m 0 -i base -g gainmap -f meta` (encode scenario 4).
 *
 * It extends Darkroom so it plugs into the same read()->resize()->quality()
 * ->sharpen()->focus()->render() pipeline the rest of Koken uses. If anything in
 * the HDR path fails (missing tooling, odd file), it falls back to emitting the
 * SDR base so a valid image is always returned.
 *
 * Cost notes. A 45-megapixel original decodes in a fraction of the time when
 * libjpeg is told the size wanted up front (`-define jpeg:size`, DCT scaling),
 * so both the base and the gain map (which Adobe writes at full resolution)
 * are read with a hint of twice the target, side by side, and the gain map is
 * scaled straight to the base's final size instead of being normalised to the
 * original's resolution first. The metadata
 * decode is the single most expensive step and never changes for a given
 * file, so its cache lives under storage/cache (which survives php-fpm
 * restarts, unlike the service's private /tmp) and the resource limits from
 * DARKROOM_MAGICK_* are passed to every convert call.
 */
class DarkroomUltraHDR extends Darkroom
{
    private $limits = [];

    public function __construct($limits = array())
    {
        $this->limits = array_merge(array(
            'thread' => false,
            'memory' => false,
            'map' => false,
        ), $limits);

        $memory_limit = (int) ini_get('memory_limit');
        if (is_numeric($memory_limit) && $memory_limit < 256) {
            ini_set('memory_limit', '256M');
        }
    }

    private function magick()
    {
        return defined('MAGICK_PATH_FINAL') ? MAGICK_PATH_FINAL : 'convert';
    }

    private function uhdr()
    {
        return defined('ULTRAHDR_PATH') ? ULTRAHDR_PATH : 'ultrahdr_app';
    }

    /** `-limit` arguments for convert, from the DARKROOM_MAGICK_* defines. */
    private function limitArgs()
    {
        $args = '';
        foreach (array('thread', 'memory', 'map') as $name) {
            if ($this->limits[$name]) {
                $args .= ' -limit ' . $name . ' ' . (int) $this->limits[$name];
            }
        }
        return $args;
    }

    public function getQuality()
    {
        $cmd = 'identify -format %Q ' . escapeshellarg($this->sourcePath);
        return (int) shell_exec($cmd);
    }

    /**
     * Rotate an HDR original in place while preserving the gain map — used at
     * ingest in lieu of the SDR drivers' destructive in-place rotation.
     *
     * Rotates both the base and the gain map, resets the EXIF orientation so the
     * file is genuinely upright (no double-rotation in browsers), then re-muxes.
     * The original is only overwritten if a valid HDR rotation was produced; on
     * any failure the original is left untouched (better an un-rotated HDR file
     * than a rotated SDR one).
     */
    public function rotate($path, $degrees)
    {
        $degrees = (int) $degrees;

        $work = $this->tempdir();
        if ($work === false) {
            return $this;
        }

        $base = $work . '/base.jpg';
        $gm   = $work . '/gainmap.jpg';
        $out  = $work . '/out.jpg';

        $q = (int) shell_exec('identify -format %Q ' . escapeshellarg($path) . ' 2>/dev/null');
        if ($q <= 0) {
            $q = 95;
        }

        // Base: physically rotate, then strip metadata except ICC (the physical
        // rotation makes the EXIF orientation tag unnecessary, and a retained
        // Exif/thumbnail would break the re-mux — see compose()).
        $this->run($this->magick() . $this->limitArgs() . ' ' . escapeshellarg($path . '[0]')
            . ' -rotate ' . $degrees
            . " +profile '!icc,*'"
            . ' -quality ' . $q . ' ' . escapeshellarg($base));

        $gmBytes = GainMap::extractGainMap($path);
        if ($gmBytes !== false && file_exists($base)) {
            file_put_contents($gm, $gmBytes);
            $this->run($this->magick() . $this->limitArgs() . ' ' . escapeshellarg($gm)
                . ' -rotate ' . $degrees . ' -strip ' . escapeshellarg($gm));

            $meta = $this->metaConfig($path, $work);
            if ($meta !== false) {
                $this->run($this->uhdr() . ' -m 0'
                    . ' -i ' . escapeshellarg($base)
                    . ' -g ' . escapeshellarg($gm)
                    . ' -f ' . escapeshellarg($meta)
                    . ' -z ' . escapeshellarg($out));
            }
        }

        if (file_exists($out) && filesize($out) > 0) {
            copy($out, $path);
        }

        $this->cleanup($work);
        return $this;
    }

    public function createImage()
    {
        // No downscale requested (target == source): pass the original through
        // untouched so the gain map is bit-for-bit preserved.
        if ($this->width >= $this->sourceWidth && $this->height >= $this->sourceHeight) {
            return $this->emit(file_get_contents($this->sourcePath));
        }

        $w = (int) $this->width;
        $h = (int) $this->height;

        return $this->emit($this->compose(
            $this->sourcePath,
            '-resize ' . $w . 'x' . $h,
            $w,
            $h,
            true
        ));
    }

    public function createCroppedImage($interstitialWidth, $interstitialHeight, $cropX, $cropY)
    {
        if ($this->sourceAspect >= $this->aspect) {
            $resizeString = 'x' . (int) $this->height;
        } else {
            $resizeString = (int) $this->width . 'x';
        }

        $geom = '-resize ' . $resizeString
              . ' -crop ' . (int) $this->width . 'x' . (int) $this->height
              . '+' . (int) $cropX . '+' . (int) $cropY
              . ' +repage';

        return $this->emit($this->compose(
            $this->sourcePath,
            $geom,
            (int) ceil($interstitialWidth),
            (int) ceil($interstitialHeight),
            true
        ));
    }

    /**
     * Split -> transform both parts with identical geometry -> re-mux.
     *
     * @param string $source   path to the Ultra HDR JPEG
     * @param string $geom     ImageMagick geometry operators (resize/crop)
     * @param int    $hintW    width of the largest intermediate the geometry
     *                         produces (the decode hint; the final size for a
     *                         plain resize, the interstitial for a crop)
     * @param int    $hintH    likewise for height
     * @param bool   $sharpen  apply unsharp to the base (not the gain map)
     * @return string|false    the resulting Ultra HDR JPEG bytes, or SDR fallback
     */
    private function compose($source, $geom, $hintW, $hintH, $sharpen)
    {
        $work = $this->tempdir();
        if ($work === false) {
            return $this->sdrFallback($source, $geom, $hintW, $hintH, $sharpen, null);
        }

        $base = $work . '/base.jpg';
        $gm   = $work . '/gainmap.jpg';
        $out  = $work . '/out.jpg';

        $unsharp = '';
        if ($sharpen && $this->sharpening) {
            $sigma = $this->sharpening * 1.3;
            $unsharp = " -unsharp 0x{$sigma}+{$this->sharpening}+0.05";
        }

        // 1. Base: primary image, resized/cropped. Strip metadata but KEEP the
        //    ICC profile: a retained Exif/thumbnail (or stale MPF) confuses
        //    ultrahdr_app's muxing and produces a corrupt multi-SOI JPEG.
        //    `+profile '!icc,*'` drops everything except colour.
        $baseCmd = $this->magick() . $this->limitArgs()
            . $this->decodeHint($hintW, $hintH)
            . ' ' . escapeshellarg($source . '[0]')
            . ' ' . $geom . $unsharp
            . " +profile '!icc,*'"
            . ' -quality ' . (int) $this->quality
            . ' ' . escapeshellarg($base);

        // 2. Gain map: extract (pure PHP) and scale it straight to the base's
        //    final pixel size. Adobe writes it at the full resolution of the
        //    original, so it gets the same decode hint as the base. The
        //    geometry's crop offsets are in base coordinates, so for a crop the
        //    gain map is first brought to the base's pre-crop (interstitial)
        //    size, then cropped identically.
        $gmBytes = GainMap::extractGainMap($source);
        if ($gmBytes === false) {
            return $this->sdrFallback($source, $geom, $hintW, $hintH, $sharpen, $work);
        }
        $gmSrc = $work . '/gm_src.jpg';
        file_put_contents($gmSrc, $gmBytes);

        if (strpos($geom, '-crop') === false) {
            $gmGeom = '-resize ' . (int) $this->width . 'x' . (int) $this->height . '!';
        } else {
            $gmGeom = '-resize ' . $hintW . 'x' . $hintH . '! ' . substr($geom, strpos($geom, '-crop'));
        }

        $gmCmd = $this->magick() . $this->limitArgs()
            . $this->decodeHint($hintW, $hintH)
            . ' ' . escapeshellarg($gmSrc)
            . ' ' . $gmGeom
            . ' -quality 94 -strip ' . escapeshellarg($gm);

        // The two resizes are independent; run them side by side.
        $this->run('{ (' . $baseCmd . ') & (' . $gmCmd . '); wait; }');

        if (!file_exists($base)) {
            return $this->sdrFallback($source, $geom, $hintW, $hintH, $sharpen, $work);
        }

        $baseSize = $this->size($base);
        if ($baseSize === false) {
            return $this->sdrFallback($source, $geom, $hintW, $hintH, $sharpen, $work, $base);
        }

        // The mux wants the two images the same size; a one-pixel rounding
        // difference is corrected here (cheap: the gain map is small by now).
        $gmSize = $this->size($gm);
        if ($gmSize !== false && $gmSize !== $baseSize) {
            $this->run($this->magick() . ' ' . escapeshellarg($gm)
                . ' -resize ' . $baseSize[0] . 'x' . $baseSize[1] . '!'
                . ' -quality 94 -strip ' . escapeshellarg($gm));
        }

        // 3. Metadata (cached — resolution independent).
        $meta = $this->metaConfig($source, $work);

        // 4. Re-mux into an Ultra HDR JPEG.
        if ($meta !== false && file_exists($gm)) {
            $this->run($this->uhdr() . ' -m 0'
                . ' -i ' . escapeshellarg($base)
                . ' -g ' . escapeshellarg($gm)
                . ' -f ' . escapeshellarg($meta)
                . ' -z ' . escapeshellarg($out));
        }

        if (file_exists($out) && filesize($out) > 0) {
            $blob = file_get_contents($out);
            $this->cleanup($work);
            return $blob;
        }

        return $this->sdrFallback($source, $geom, $hintW, $hintH, $sharpen, $work, $base);
    }

    /**
     * libjpeg can decode at 1/2, 1/4 or 1/8 scale for free (DCT scaling), and
     * ImageMagick picks the smallest scale that still covers the requested size.
     * Asking for twice the target keeps full quality through the final resize.
     */
    private function decodeHint($w, $h)
    {
        if ($w <= 0 || $h <= 0) {
            return '';
        }
        return ' -define jpeg:size=' . (2 * (int) $w) . 'x' . (2 * (int) $h);
    }

    /** @return array{0:int,1:int}|false pixel size of a JPEG on disk. */
    private function size($path)
    {
        $size = @getimagesize($path);
        return $size ? array((int) $size[0], (int) $size[1]) : false;
    }

    /**
     * Decode the gain-map metadata once and cache it (keyed by file + mtime).
     * Returns the path to the cfg file, or false on failure.
     */
    private function metaConfig($source, $work)
    {
        $key = md5($source . ':' . @filemtime($source));
        $cache = $this->metaCacheDir() . '/koken_uhdr_' . $key . '.cfg';

        if (file_exists($cache) && filesize($cache) > 0) {
            return $cache;
        }

        $throwaway = $work . '/decode.raw';
        $this->run($this->uhdr() . ' -m 1'
            . ' -j ' . escapeshellarg($source)
            . ' -f ' . escapeshellarg($cache)
            . ' -z ' . escapeshellarg($throwaway));
        @unlink($throwaway);

        return (file_exists($cache) && filesize($cache) > 0) ? $cache : false;
    }

    /**
     * Where the metadata cache lives: storage/cache/uhdr under the Koken root,
     * which persists across php-fpm restarts (the service's /tmp usually does
     * not) and is swept by the same housekeeping as the image cache. Falls
     * back to the system temp dir if that cannot be created.
     */
    private function metaCacheDir()
    {
        $dir = dirname(__DIR__, 3) . '/storage/cache/uhdr';
        if (is_dir($dir) || @mkdir($dir, 0755, true)) {
            if (is_writable($dir)) {
                return $dir;
            }
        }
        return sys_get_temp_dir();
    }

    /**
     * Last resort: emit a valid SDR JPEG (the resized base) so the request never
     * fails, even if the HDR tooling is unavailable.
     */
    private function sdrFallback($source, $geom, $hintW, $hintH, $sharpen, $work, $base = null)
    {
        $unsharp = '';
        if ($sharpen && $this->sharpening) {
            $sigma = $this->sharpening * 1.3;
            $unsharp = " -unsharp 0x{$sigma}+{$this->sharpening}+0.05";
        }

        $blob = false;
        if ($base !== null && file_exists($base)) {
            $blob = file_get_contents($base);
        } else {
            $blob = shell_exec($this->magick() . $this->limitArgs()
                . $this->decodeHint($hintW, $hintH)
                . ' ' . escapeshellarg($source . '[0]')
                . ' ' . $geom . $unsharp
                . ' -quality ' . (int) $this->quality . ' jpg:-');
        }

        // If ImageMagick is unavailable, degrade to a GD-produced SDR image
        // (GD reads the primary of an Ultra HDR file fine) rather than erroring.
        if (empty($blob)) {
            $blob = $this->gdFallback($source);
        }

        if ($work !== null) {
            $this->cleanup($work);
        }
        return $blob;
    }

    private function gdFallback($source)
    {
        if (!function_exists('imagecreatefromjpeg')) {
            return false;
        }

        $img = @imagecreatefromjpeg($source);
        if (!$img) {
            return false;
        }

        $tw = max(1, (int) $this->width);
        $th = max(1, (int) $this->height);
        $scaled = imagescale($img, $tw, $th);
        if (!$scaled) {
            return false;
        }

        ob_start();
        imagejpeg($scaled, null, min((int) $this->quality, 99));
        return ob_get_clean();
    }

    private function emit($blob)
    {
        if ($this->path) {
            file_put_contents($this->path, $blob);
            return $this;
        }
        return $blob;
    }

    private function run($cmd)
    {
        return shell_exec($cmd . ' 2>/dev/null');
    }

    private function tempdir()
    {
        $dir = sys_get_temp_dir() . '/koken_uhdr_' . uniqid('', true);
        return @mkdir($dir, 0700) ? $dir : false;
    }

    private function cleanup($dir)
    {
        foreach ((array) @glob($dir . '/*') as $f) {
            @unlink($f);
        }
        @rmdir($dir);
    }
}
