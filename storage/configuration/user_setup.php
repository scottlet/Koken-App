<?php

	// Path to ImageMagick on your server, if it is in a non-standard location.
	// define('MAGICK_PATH', 'convert');

	// Path to ffmpeg on your server
	define('FFMPEG_PATH', 'ffmpeg');

	// Path to qt-faststart, used to move the moov atom to the front of uploaded
	// videos so playback can start before the whole file has downloaded. It is
	// found next to ffmpeg (or on PATH) by default; set this if it lives elsewhere.
	// Uploads are left untouched if it cannot be found.
	// define('QT_FASTSTART_PATH', '/usr/bin/qt-faststart');

	// Path to the libultrahdr CLI (ultrahdr_app), used to resize HDR / gain-map
	// images without destroying the gain map. Uncomment and set to enable HDR output.
	// define('ULTRAHDR_PATH', '/usr/local/bin/ultrahdr_app');

	// Which size presets are served as HDR when the original carries a gain map.
	// Smaller presets stay SDR (using the gain map's built-in SDR fallback).
	// define('KOKEN_HDR_PRESETS', 'xlarge,huge');

	// By default, Koken makes requests in parallel to improve performance.
	// On some hosts, this can cause you to exceed your resource allotment.
	// Uncomment this line and/or lower the number if you are having issues.
	// define('MAX_PARALLEL_REQUESTS', 4);