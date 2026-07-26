<?php

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/collector.php';

try {
    $config = require __DIR__ . '/config.php';

    $debugOverride = $_GET['debug'] ?? null;
    if ($debugOverride !== null) {
        $config['settings']['debug_sync'] = $debugOverride === '1';
    }

    $refresh = maybe_refresh_stats($config, true);

    if (!empty($refresh['error'])) {
        send_json_response([
            'status' => 'error',
            'message' => $refresh['error'],
            'refresh' => $refresh,
        ], 500);
    } elseif (!empty($refresh['in_progress'])) {
        send_json_response([
            'status' => 'in_progress',
            'message' => 'Refresh is already running',
            'refresh' => $refresh,
        ], 202);
    } else {
        send_json_response([
            'status' => $refresh['summary']['status'] ?? 'success',
            'debug_sync' => is_sync_debug_enabled($config),
            'refresh' => $refresh,
        ]);
    }
} catch (Throwable $e) {
    send_json_response([
        'status' => 'error',
        'message' => truncate_error_message($e->getMessage()),
        'error' => truncate_error_message($e->getMessage()),
        'refresh' => null,
    ], 500);
}
