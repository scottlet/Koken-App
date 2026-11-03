#!/usr/bin/env php
<?php
/*
 * regen-video-posters - rebuild the poster frame of every video already in
 * the library and remove the unused sample frames upstream Koken left behind.
 *
 * WHY THIS EXISTS
 *
 * Upstream Koken wrote up to twelve full-resolution JPEG frames per video
 * into <original>_previews/ and then picked one of them from an unsorted
 * directory listing, so the poster was effectively random and the other
 * frames were never used. Uploads now write a single frame (see
 * app/koken/ffmpeg.php). This tool brings existing videos in line: for each
 * one it extracts the poster afresh, deletes the old frames, stores the new
 * filename in content.lg_preview, and clears that item's cached derivatives
 * so the new poster shows up everywhere without a re-upload.
 *
 * USAGE (as the user that owns storage/)
 *
 *   php regen-video-posters.php --root=/path/to/koken --dry-run   # report only
 *   php regen-video-posters.php --root=/path/to/koken             # do it
 *   php regen-video-posters.php --root=/path/to/koken --id=123    # one video
 *   php regen-video-posters.php --root=/path/to/koken --limit=10  # first ten
 *
 * --root is the Koken directory (the one containing api.php and storage/).
 * It defaults to the parent of this file, so the tool also works from a
 * checkout, but it does not need to live inside the web root: copy this
 * one file anywhere on the server and point --root at the install. Reads
 * storage/configuration/database.php and user_setup.php from there, so it
 * needs no other arguments. Safe to re-run.
 */

if (PHP_SAPI !== 'cli') {
    exit("This tool runs from the command line only.\n");
}

$options = getopt('', ['dry-run', 'id:', 'limit:', 'root:', 'help']);
if (isset($options['help'])) {
    exit(preg_replace('~^/\*\n|\n \*/\n.*$~s', '', substr(file_get_contents(__FILE__), strpos(file_get_contents(__FILE__), '/*'))) . "\n");
}

$root = rtrim(isset($options['root']) ? $options['root'] : dirname(__DIR__), '/\\') . DIRECTORY_SEPARATOR;
if (!is_file($root . 'api.php') || !is_dir($root . 'storage')) {
    fail("$root is not a Koken install (no api.php and storage/ there). Pass --root=/path/to/koken.");
}
define('FCPATH', $root);

$dry_run = isset($options['dry-run']);
$only_id = isset($options['id']) ? (int) $options['id'] : null;
$limit = isset($options['limit']) ? (int) $options['limit'] : null;

// Mirror the constants app/application/config/koken.php derives from
// user_setup.php, without loading CodeIgniter.
$user_setup = $root . 'storage' . DIRECTORY_SEPARATOR . 'configuration' . DIRECTORY_SEPARATOR . 'user_setup.php';
if (file_exists($user_setup)) {
    include $user_setup;
}
define('FFMPEG_PATH_FINAL', defined('FFMPEG_PATH') ? FFMPEG_PATH : 'ffmpeg');

if (!function_exists('make_child_dir')) {
    function make_child_dir($path)
    {
        return is_dir($path) || mkdir($path, 0755, true);
    }
}

require $root . 'app' . DIRECTORY_SEPARATOR . 'koken' . DIRECTORY_SEPARATOR . 'ffmpeg.php';

$probe = new FFmpeg();
$version = $probe->version();
if (!$version) {
    fail('ffmpeg is not runnable as "' . FFMPEG_PATH_FINAL . '". Set FFMPEG_PATH in storage/configuration/user_setup.php.');
}

$db = connect_database($root);
$prefix = $db['prefix'];
$mysqli = $db['link'];

$originals = $root . 'storage' . DIRECTORY_SEPARATOR . 'originals';
$cache_images = $root . 'storage' . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'images';

if (function_exists('posix_geteuid') && is_dir($originals) && fileowner($originals) !== posix_geteuid()) {
    $owner = function_exists('posix_getpwuid') ? (posix_getpwuid(fileowner($originals))['name'] ?? fileowner($originals)) : fileowner($originals);
    warn("storage/originals is owned by '$owner' but you are running as a different user. New files would get the wrong owner; run this as '$owner' (e.g. sudo -u $owner php tools/regen-video-posters.php).");
    if (!$dry_run) {
        fail('Refusing to write files as the wrong user. Re-run as the owner, or with --dry-run to preview.');
    }
}

$sql = "SELECT id, internal_id, filename, lg_preview, storage_url FROM {$prefix}content WHERE file_type = 1";
if ($only_id) {
    $sql .= ' AND id = ' . $only_id;
}
$sql .= ' ORDER BY id';
if ($limit) {
    $sql .= ' LIMIT ' . $limit;
}

$result = $mysqli->query($sql) or fail('Query failed: ' . $mysqli->error);

$stats = ['videos' => 0, 'updated' => 0, 'unchanged' => 0, 'failed' => 0, 'skipped' => 0, 'frames_removed' => 0, 'bytes_freed' => 0];

echo 'ffmpeg ' . $version . ', poster: first frame' . ($dry_run ? ' (dry run, nothing will be changed)' : '') . "\n\n";

while ($row = $result->fetch_assoc()) {
    $stats['videos']++;
    $label = sprintf('#%d %s', $row['id'], $row['filename']);

    if (!empty($row['storage_url'])) {
        $stats['skipped']++;
        echo "$label: skipped, stored externally\n";
        continue;
    }

    $dir = $originals . DIRECTORY_SEPARATOR . substr($row['internal_id'], 0, 2) . DIRECTORY_SEPARATOR . substr($row['internal_id'], 2, 2);
    $path = $dir . DIRECTORY_SEPARATOR . $row['filename'];
    if (!is_file($path)) {
        $stats['failed']++;
        echo "$label: FAILED, original not found at $path\n";
        continue;
    }

    $previews = $path . '_previews' . DIRECTORY_SEPARATOR;
    $old_files = array_filter(glob($previews . '*') ?: [], 'is_file');
    $old_bytes = array_sum(array_map('filesize', $old_files));

    $ffmpeg = new FFmpeg($path);
    $padded_id = str_pad($row['id'], 6, '0', STR_PAD_LEFT);
    $cache_dir = $cache_images . DIRECTORY_SEPARATOR . substr($padded_id, 0, 3) . DIRECTORY_SEPARATOR . substr($padded_id, 3);

    if ($dry_run) {
        printf("%s: would remove %d old frame(s) (%s), clear %s\n", $label, count($old_files), human_bytes($old_bytes), is_dir($cache_dir) ? 'cache' : 'no cache');
        $stats['frames_removed'] += count($old_files);
        $stats['bytes_freed'] += $old_bytes;
        continue;
    }

    $lg_preview = $ffmpeg->create_thumbs();
    if ($lg_preview === null) {
        $stats['failed']++;
        echo "$label: FAILED, ffmpeg could not extract a frame (old frames removed, poster is now missing)\n";
        $mysqli->query("UPDATE {$prefix}content SET lg_preview = NULL, file_modified_on = " . time() . ' WHERE id = ' . (int) $row['id']);
        delete_directory($cache_dir);
        continue;
    }

    $new_files = array_filter(glob($previews . '*') ?: [], 'is_file');
    $new_bytes = array_sum(array_map('filesize', $new_files));

    $stmt = $mysqli->prepare("UPDATE {$prefix}content SET lg_preview = ?, file_modified_on = ? WHERE id = ?");
    $now = time();
    $stmt->bind_param('sii', $lg_preview, $now, $row['id']);
    $stmt->execute() or fail('Update failed for ' . $label . ': ' . $stmt->error);
    $stmt->close();

    delete_directory($cache_dir);

    $stats['updated']++;
    $stats['frames_removed'] += count($old_files);
    $stats['bytes_freed'] += $old_bytes - $new_bytes;
    printf("%s: poster %s -> %s, %d old frame(s) removed, %s freed\n", $label, $row['lg_preview'] ?: 'none', $lg_preview, count($old_files), human_bytes($old_bytes - $new_bytes));
}

if (!$dry_run && $stats['updated'] + $stats['failed'] > 0) {
    // What Koken itself does after any write: invalidate the API response
    // cache (a stamp file, compared against each cached response's mtime)
    // and drop the rendered site cache.
    $api_stamp = $root . 'storage' . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'stamp';
    make_child_dir(dirname($api_stamp));
    touch($api_stamp);
    delete_directory($root . 'storage' . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'site', true);
}

printf(
    "\n%d video(s): %d updated, %d failed, %d skipped. %d old frame(s) %s, %s %s.\n",
    $stats['videos'],
    $stats['updated'],
    $stats['failed'],
    $stats['skipped'],
    $stats['frames_removed'],
    $dry_run ? 'would be removed' : 'removed',
    human_bytes($stats['bytes_freed']),
    $dry_run ? 'would be freed' : 'freed'
);

exit($stats['failed'] > 0 ? 1 : 0);

function connect_database($root)
{
    $file = $root . 'storage' . DIRECTORY_SEPARATOR . 'configuration' . DIRECTORY_SEPARATOR . 'database.php';
    if (!file_exists($file)) {
        fail("No database configuration at $file. Run this from a Koken install.");
    }

    $config = include $file;
    if (isset($KOKEN_DATABASE)) {
        $config = $KOKEN_DATABASE;
    }
    if (!is_array($config) || empty($config['database'])) {
        fail("Could not read database settings from $file.");
    }

    $host = $config['hostname'] ?? 'localhost';
    $port = null;
    $socket = !empty($config['socket']) ? $config['socket'] : null;
    if (str_contains($host, ':')) {
        [$host, $rest] = explode(':', $host, 2);
        if (str_starts_with($rest, '/')) {
            $socket = $rest;
        } elseif (is_numeric($rest)) {
            $port = (int) $rest;
        }
        $host = $host === '' ? 'localhost' : $host;
    }

    mysqli_report(MYSQLI_REPORT_OFF);
    $link = @new mysqli($host, $config['username'] ?? '', $config['password'] ?? '', $config['database'], $port, $socket);
    if ($link->connect_error) {
        fail('Could not connect to the database: ' . $link->connect_error);
    }
    $link->set_charset('utf8mb4');

    return ['link' => $link, 'prefix' => $config['prefix'] ?? ''];
}

function delete_directory($dir, $contents_only = false)
{
    if (!is_dir($dir)) {
        return;
    }
    foreach (scandir($dir) as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $full = $dir . DIRECTORY_SEPARATOR . $entry;
        if (is_dir($full) && !is_link($full)) {
            delete_directory($full);
        } else {
            @unlink($full);
        }
    }
    if (!$contents_only) {
        @rmdir($dir);
    }
}

function human_bytes($bytes)
{
    $units = ['B', 'KB', 'MB', 'GB'];
    $i = 0;
    $bytes = (float) $bytes;
    while (abs($bytes) >= 1024 && $i < count($units) - 1) {
        $bytes /= 1024;
        $i++;
    }
    return sprintf($i === 0 ? '%d %s' : '%.1f %s', $bytes, $units[$i]);
}

function warn($message)
{
    fwrite(STDERR, "Warning: $message\n");
}

function fail($message)
{
    fwrite(STDERR, "Error: $message\n");
    exit(2);
}
