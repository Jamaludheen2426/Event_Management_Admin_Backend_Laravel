<?php

declare(strict_types=1);

/*
| Uploads. Where files go (local disk or an S3-compatible bucket) is chosen
| per company in Settings -> Media (settings group "media"); these are the
| fixed parts.
*/
return [

    // The local driver writes here and serves from APP_URL/uploads (v1 layout).
    'local_root' => env('MEDIA_LOCAL_ROOT', public_path('uploads')),

    'local_url' => rtrim((string) env('APP_URL', ''), '/').'/uploads',

    // v1: 10 MB per file, at most 10 files per multi-upload.
    'max_kilobytes' => 10 * 1024,

    'max_files' => 10,

    'allowed_mimes' => [
        'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/svg+xml', 'image/x-icon',
        'application/pdf', 'video/mp4', 'video/webm', 'audio/mpeg', 'audio/wav',
    ],

    // GET /media/proxy fetches at most this much from remote storage.
    'proxy_max_bytes' => 15 * 1024 * 1024,

    // ffmpeg for gallery / wish videos (transcode, mute, poster). Missing =
    // videos are stored as uploaded and a requested mute is refused.
    'ffmpeg' => env('FFMPEG_PATH', 'ffmpeg'),

];
