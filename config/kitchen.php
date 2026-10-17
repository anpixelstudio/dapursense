<?php

return [

    'registration_enabled' => env('KITCHEN_REGISTRATION_ENABLED', true),

    'email_verification' => env('KITCHEN_EMAIL_VERIFICATION', 'required'),

    'storage_disk' => 'local',

    'upload' => [
        'max_bytes' => (int) env('KITCHEN_UPLOAD_MAX_BYTES', 500 * 1024 * 1024),
        'max_duration_ms' => (int) env('KITCHEN_UPLOAD_MAX_DURATION_MS', 30 * 60 * 1000),
        'extensions' => ['mp4', 'mov', 'mkv'],
        'browser_codecs' => ['h264'],
    ],

    'retention_days' => [
        'original_video' => (int) env('KITCHEN_RETENTION_ORIGINAL_DAYS', 7),
        'observations' => (int) env('KITCHEN_RETENTION_OBSERVATION_DAYS', 7),
        'evidence' => (int) env('KITCHEN_RETENTION_EVIDENCE_DAYS', 30),
        'reports' => (int) env('KITCHEN_RETENTION_REPORT_DAYS', 90),
    ],

    'storage' => [
        'warn_percent' => (float) env('KITCHEN_STORAGE_WARN_PERCENT', 80),
        'block_percent' => (float) env('KITCHEN_STORAGE_BLOCK_PERCENT', 90),
    ],

    'evidence' => [
        'clip_before_ms' => 5000,
        'clip_after_ms' => 10000,
    ],

    'worker' => [
        'url' => env('AI_SERVICE_URL', 'http://127.0.0.1:8100'),
        'service_token' => env('AI_SERVICE_TOKEN'),
        'callback_url' => env('AI_CALLBACK_URL', env('APP_URL')),
        'heartbeat_stall_seconds' => 60,
        'max_attempts' => 3,
        'contract_version' => 1,
    ],

    'uptime' => [
        'tcp_timeout_seconds' => (float) env('KITCHEN_UPTIME_TCP_TIMEOUT', 3),
        'stats_retention_days' => (int) env('KITCHEN_UPTIME_STATS_DAYS', 90),
    ],

    'binaries' => [
        'ffmpeg' => env('FFMPEG_BINARY', 'ffmpeg'),
        'ffprobe' => env('FFPROBE_BINARY', 'ffprobe'),
    ],

    'install' => [
        'lock_key' => 7_348_201_945,
        'fail_at_step' => env('KITCHEN_INSTALL_FAIL_AT_STEP'),
    ],

];
