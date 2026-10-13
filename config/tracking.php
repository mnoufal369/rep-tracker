<?php

return [

    /*
    | Raw GPS fixes live in the partitioned `location_points` table ("hot" storage)
    | for this many days. Once a rep-day has been archived *and verified*, the raw
    | rows older than this window are removed (whole partitions are dropped on MySQL).
    */
    'hot_retention_days' => (int) env('TRACKING_HOT_RETENTION_DAYS', 90),

    /*
    | A day is only archived once it is this many hours past its local midnight,
    | which leaves room for devices that come back online late and flush their buffer.
    */
    'archive_grace_hours' => (int) env('TRACKING_ARCHIVE_GRACE_HOURS', 6),

    // Maximum number of points accepted in one ingestion request.
    'max_batch_size' => 1000,

    // Points recorded further than this in the future (device clock skew) are rejected.
    'max_future_skew_seconds' => 300,

    'timeline' => [
        // A rep is "stopped" when they stay within this radius for at least `stop_min_seconds`.
        'stop_radius_m' => 50,
        'stop_min_seconds' => 180,
        // No fix for longer than this is reported as a coverage gap.
        'gap_seconds' => 300,
    ],

    // Douglas-Peucker tolerance used when drawing the track on a map.
    'map_simplify_tolerance_m' => 3,

    // Enables the POST /api/reps/{rep}/days/{date}/simulate endpoint.
    'simulator_enabled' => (bool) env('TRACKING_SIMULATOR_ENABLED', true),

    'default_timezone' => env('TRACKING_DEFAULT_TIMEZONE', 'Europe/Amsterdam'),
];
