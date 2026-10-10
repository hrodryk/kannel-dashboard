<?php

return [

    'poll_interval' => (int) env('KANNEL_POLL_INTERVAL', 30),

    'retention_days' => (int) env('KANNEL_RETENTION_DAYS', 30),

    'consecutive_failures_before_unreachable' => (int) env('KANNEL_UNREACHABLE_AFTER', 3),

    'http' => [
        'timeout_seconds' => (int) env('KANNEL_HTTP_TIMEOUT', 10),
        'connect_timeout_seconds' => (int) env('KANNEL_HTTP_CONNECT_TIMEOUT', 5),
        'retry_max_attempts' => (int) env('KANNEL_HTTP_RETRY_MAX', 4),
        'retry_base_seconds' => (int) env('KANNEL_HTTP_RETRY_BASE', 1),
        'retry_cap_seconds' => (int) env('KANNEL_HTTP_RETRY_CAP', 30),
    ],

    'throughput' => [
        'baseline_hours' => (int) env('KANNEL_BASELINE_HOURS', 24),
        'drop_threshold_fraction' => (float) env('KANNEL_THROUGHPUT_DROP_FRACTION', 0.5),
        'rate_window_minutes' => (int) env('KANNEL_RATE_WINDOW_MINUTES', 5),
    ],

    'queue' => [
        'warning_threshold' => (int) env('KANNEL_QUEUE_WARNING', 100),
        'critical_threshold' => (int) env('KANNEL_QUEUE_CRITICAL', 1000),
    ],

    'channels' => [
        'webhook' => [
            'hmac_header' => env('KANNEL_WEBHOOK_HMAC_HEADER', 'X-Kannel-Signature'),
            'hmac_algo' => env('KANNEL_WEBHOOK_HMAC_ALGO', 'sha256'),
            'timeout_seconds' => (int) env('KANNEL_WEBHOOK_TIMEOUT', 10),
        ],
    ],

    'admin' => [
        'status_path_xml' => env('KANNEL_STATUS_PATH_XML', '/status.xml'),
        'status_path_json' => env('KANNEL_STATUS_PATH_JSON', '/status.json'),
        'store_status_path_xml' => env('KANNEL_STORE_STATUS_PATH_XML', '/store-status.xml'),
        'store_status_path_json' => env('KANNEL_STORE_STATUS_PATH_JSON', '/store-status.json'),
    ],

    'status_paths' => [
        'xml' => env('KANNEL_STATUS_PATH_XML', '/status.xml'),
        'json' => env('KANNEL_STATUS_PATH_JSON', '/status.json'),
    ],

];
