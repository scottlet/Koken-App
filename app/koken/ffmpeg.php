<?php

class FFmpeg
{
    public $ffmpeg = 'ffmpeg';
    public $info = null;
    public $duration = 0;
    public $dimensions = 0;

    public function __construct(public $path = false)
    {
        $this->ffmpeg = FFMPEG_PATH_FINAL;
    }

    public function version()
    {
        if (function_exists('exec') && (DIRECTORY_SEPARATOR == '/' || (DIRECTORY_SEPARATOR == '\\' && $this->ffmpeg != 'ffmpeg'))) {
            exec($this->ffmpeg . ' -version 2>&1', $out);
            if (empty($out)) {
                return false;
            } else {
                if (str_contains(strtolower($out[0]), 'ffmpeg') && preg_match('/(\d+.\d+(.\d+)?)/', $out[0], $matches)) {
                    return $matches[1];
                } else {
                    return false;
                }
            }
        }
        return false;
    }

    public function check()
    {
        return file_exists($this->path);
    }

    public function info()
    {
        exec($this->ffmpeg . " -i \"{$this->path}\" 2>&1", $this->info);
    }

    public function create_thumbs()
    {
        $target_directory = dirname($this->path) . DIRECTORY_SEPARATOR . basename($this->path) . '_previews' . DIRECTORY_SEPARATOR;
        make_child_dir($target_directory);
        $duration = $this->duration() - 2;
        $bits = ceil($duration/12);
        if ($bits == 0) {
            $bits = 1;
        }
        $rate = 1/$bits;
        if ($rate < 0.1) {
            $rate = 0.1;
        }

        $i = 1;
        $cmd = [];
        while ($i < $duration) {
            $i_str = str_pad($i, 5, '0', STR_PAD_LEFT);
            $cmd[] = $this->ffmpeg . " -ss $i -i \"{$this->path}\" -vframes 1 -an -f mjpeg \"$i_str.jpg\"";
            $i += $bits;
        }

        // Clips under ~4s produce no sample points above (the loop starts at
        // 1s and stops 2s short of the end). An empty command list used to be
        // a harmless warning; on PHP 8 exec('') is a fatal ValueError, which
        // surfaced as a bare 500 on upload. Take one frame from the midpoint
        // instead so every video gets a preview.
        if (empty($cmd)) {
            $mid = (int) max(0, floor($this->duration() / 2));
            $mid_str = str_pad($mid, 5, '0', STR_PAD_LEFT);
            $cmd[] = $this->ffmpeg . " -ss $mid -i \"{$this->path}\" -vframes 1 -an -f mjpeg \"$mid_str.jpg\"";
        }

        chdir($target_directory);
        if (DIRECTORY_SEPARATOR == '\\') {
            foreach ($cmd as $c) {
                exec($c);
            }
        } else {
            $cmd = implode(' && ', $cmd);
            exec($cmd);
        }

        $files = directory_map($target_directory, true);
        if ($files) {
            return $files[ max(0, floor(count($files)/2)-1) ] . ':50:50';
        } else {
            return null;
        }
    }

    public function dimensions()
    {
        if (is_null($this->info)) {
            $this->info();
        }

        foreach ($this->info as $line) {
            if (str_contains($line, 'Video:')) {
                preg_match('/([0-9]{2,5})x([0-9]{2,5})/', $line, $matches);
                list(, $w, $h) = $matches;
                $this->dimensions = array($w, $h);
                return $this->dimensions;
            }
        }
    }

    /**
     * Move the moov atom to the front of the file in place, so browsers can
     * start playback before the whole file has downloaded. Phones write it at
     * the end.
     *
     * Uses qt-faststart rather than an ffmpeg remux on purpose: ffmpeg's mp4
     * muxer drops Apple's vexu box (flattening spatial video to mono) and
     * refuses the metadata tracks some phones add. qt-faststart only reorders
     * atoms and patches chunk offsets; every other byte is preserved.
     *
     * Returns true if the file was rewritten, false if it was left alone
     * (already faststart, tool missing, or any failure). The original is
     * only replaced once a complete output exists.
     */
    public function faststart()
    {
        if (!function_exists('exec') || !defined('QT_FASTSTART_PATH_FINAL')) {
            return false;
        }

        $bin = QT_FASTSTART_PATH_FINAL;
        if (str_contains($bin, DIRECTORY_SEPARATOR)) {
            if (!is_executable($bin)) {
                return false;
            }
        } else {
            exec('command -v ' . escapeshellarg($bin) . ' 2>/dev/null', $found, $status);
            if ($status !== 0 || empty($found)) {
                return false;
            }
        }

        $tmp = $this->path . '.faststart.tmp';
        @unlink($tmp);

        // qt-faststart exits 0 and writes nothing when moov is already first,
        // so the presence of the output file is the signal, not the exit code.
        exec(escapeshellarg($bin) . ' ' . escapeshellarg($this->path) . ' ' . escapeshellarg($tmp) . ' 2>&1', $out, $status);

        if ($status !== 0 || !file_exists($tmp)) {
            @unlink($tmp);
            return false;
        }

        if (filesize($tmp) !== filesize($this->path) || !rename($tmp, $this->path)) {
            @unlink($tmp);
            return false;
        }

        // Cached ffmpeg -i output stays valid: the streams are untouched.
        return true;
    }

    /**
     * The container's creation_time as a Unix timestamp, or null if absent.
     *
     * ffmpeg prints it in the same `-i` dump used for duration/dimensions,
     * as an ISO-8601 UTC instant (e.g. 2026-09-15T05:51:11.000000Z). The
     * first occurrence is the format-level tag; stream-level copies follow.
     */
    public function creation_time()
    {
        if (is_null($this->info)) {
            $this->info();
        }

        foreach ($this->info as $line) {
            if (preg_match('/^\s*creation_time\s*:\s*(\S+)/', $line, $matches)) {
                $ts = strtotime($matches[1]);
                return $ts > 0 ? $ts : null;
            }
        }

        return null;
    }

    public function duration()
    {
        if ($this->duration > 0) {
            return $this->duration;
        }

        if (is_null($this->info)) {
            $this->info();
        }

        foreach ($this->info as $line) {
            if (str_contains($line, 'Duration:')) {
                preg_match('/Duration: ([0-9]{2}):([0-9]{2}):([0-9]{2})/', $line, $matches);
                list(, $h, $m, $s) = $matches;
                $this->duration = ($h*60*60) + ($m*60) + $s;
                return $this->duration;
            }
        }
    }
}
