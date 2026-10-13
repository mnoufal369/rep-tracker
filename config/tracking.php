<?php

return [

    // All reps work in the same city, so one timezone decides where a "day" starts and ends.
    'timezone' => env('TRACKING_TIMEZONE', 'Europe/Amsterdam'),

    // Raw points are kept this many days. Older days are only kept in the compressed archive.
    'hot_retention_days' => (int) env('TRACKING_HOT_RETENTION_DAYS', 90),

    // No point for longer than this (seconds) is reported as a gap in the rep's coverage.
    'gap_seconds' => 300,

    // Maximum number of points in one upload.
    'max_batch_size' => 1000,
];
