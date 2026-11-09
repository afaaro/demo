<?php
declare(strict_types=1);

/**
 * Video upload re-encode settings (module-local).
 * Set enabled => false to store originals without calling ffmpeg.
 */
return [
    'enabled'       => true,
    'ffmpeg_path'   => PHP_OS_FAMILY === 'Windows'
        ? 'C:\\ffmpeg\\bin\\ffmpeg.exe'
        : '/usr/bin/ffmpeg',
    'max_height'    => 720,
    'video_bitrate' => '2500k',
    'audio_bitrate' => '128k',
];
