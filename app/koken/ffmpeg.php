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

    /**
     * Write the poster frame for this video and return the lg_preview value
     * ("<file>:50:50"), or null if no frame could be extracted.
     *
     * The poster is the first frame of the video. Upstream sampled up to
     * twelve full-resolution frames into the _previews directory and then
     * chose one from an unsorted directory listing, so the poster was
     * effectively random and the other frames sat unused on disk. Only the
     * one frame is written now, and whatever was in the directory before is
     * removed, so replacing a video replaces its poster.
     */
    public function create_thumbs()
    {
        $target_directory = dirname($this->path) . DIRECTORY_SEPARATOR . basename($this->path) . '_previews' . DIRECTORY_SEPARATOR;
        make_child_dir($target_directory);

        foreach (glob($target_directory . '*') ?: [] as $old) {
            if (is_file($old)) {
                @unlink($old);
            }
        }

        $name = '00000.jpg';
        $out = $target_directory . $name;
        exec($this->ffmpeg . ' -nostdin -y -i ' . escapeshellarg($this->path) . ' -frames:v 1 -an -f mjpeg ' . escapeshellarg($out) . ' 2>&1', $output, $status);
        if ($status === 0 && is_file($out) && filesize($out) > 0) {
            return $name . ':50:50';
        }

        @unlink($out);
        return null;
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
