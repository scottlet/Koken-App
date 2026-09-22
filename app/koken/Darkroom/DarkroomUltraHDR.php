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
 *                normalised to the base resolution then resized/cropped the same.
 *   3. meta   = the gain-map metadata (maxContentBoost, gamma, …), pulled once
 *               via `ultrahdr_app -m 1` and cached (it is resolution-independent).
 *   4. mux    = `ultrahdr_app -m 0 -i base -g gainmap -f meta` (encode scenario 4).
 *
 * It extends Darkroom so it plugs into the same read()->resize()->quality()
 * ->sharpen()->focus()->render() pipeline the rest of Koken uses. If anything in
 * the HDR path fails (missing tooling, odd file), it falls back to emitting the
 * SDR base so a valid image is always returned.
 */
class DarkroomUltraHDR extends Darkroom
{
    private $limits = [];

    public function __construct($limits = array())
    {
        $this->limits = $limits;

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
        $this->run($this->magick() . ' ' . escapeshellarg($path . '[0]')
            . ' -rotate ' . $degrees
            . " +profile '!icc,*'"
            . ' -quality ' . $q . ' ' . escapeshellarg($base));

        $gmBytes = GainMap::extractGainMap($path);
        if ($gmBytes !== false && file_exists($base)) {
            file_put_contents($gm, $gmBytes);
            $this->run($this->magick() . ' ' . escapeshellarg($gm)
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

        $geom = '-resize ' . (int) $this->width . 'x' . (int) $this->height;
        return $this->emit($this->compose($this->sourcePath, $geom, true));
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

        return $this->emit($this->compose($this->sourcePath, $geom, true));
    }

    /**
     * Split -> transform both parts with identical geometry -> re-mux.
     *
     * @param string $source   path to the Ultra HDR JPEG
     * @param string $geom      ImageMagick geometry operators (resize/crop)
     * @param bool   $sharpen   apply unsharp to the base (not the gain map)
     * @return string|false     the resulting Ultra HDR JPEG bytes, or SDR fallback
     */
    private function compose($source, $geom, $sharpen)
    {
        $work = $this->tempdir();
        if ($work === false) {
            return $this->sdrFallback($source, $geom, $sharpen, null);
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
        $this->run($this->magick() . ' ' . escapeshellarg($source . '[0]')
            . ' ' . $geom . $unsharp
            . " +profile '!icc,*'"
            . ' -quality ' . (int) $this->quality
            . ' ' . escapeshellarg($base));

        // 2. Gain map: extract (pure PHP), normalise to the source resolution so
        //    it shares the base's coordinate system, then apply the same geometry.
        $gmBytes = GainMap::extractGainMap($source);
        if ($gmBytes === false || !file_exists($base)) {
            return $this->sdrFallback($source, $geom, $sharpen, $work);
        }
        $gmSrc = $work . '/gm_src.jpg';
        file_put_contents($gmSrc, $gmBytes);

        $this->run($this->magick() . ' ' . escapeshellarg($gmSrc)
            . ' -resize ' . (int) $this->sourceWidth . 'x' . (int) $this->sourceHeight . '!'
            . ' ' . $geom
            . ' -quality 94 -strip ' . escapeshellarg($gm));

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

        return $this->sdrFallback($source, $geom, $sharpen, $work, $base);
    }

    /**
     * Decode the gain-map metadata once and cache it (keyed by file + mtime).
     * Returns the path to the cfg file, or false on failure.
     */
    private function metaConfig($source, $work)
    {
        $key = md5($source . ':' . @filemtime($source));
        $cache = sys_get_temp_dir() . '/koken_uhdr_' . $key . '.cfg';

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
     * Last resort: emit a valid SDR JPEG (the resized base) so the request never
     * fails, even if the HDR tooling is unavailable.
     */
    private function sdrFallback($source, $geom, $sharpen, $work, $base = null)
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
            $blob = shell_exec($this->magick() . ' ' . escapeshellarg($source . '[0]')
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
