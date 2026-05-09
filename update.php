<?php

header('Content-Type: application/json');

$config = require __DIR__ . '/config.php';
require_once __DIR__ . '/collector.php';

$debugOverride = $_GET['debug'] ?? null;
if ($debugOverride !== null) {
    $config['settings']['debug_sync'] = $debugOverride === '1';
}

$refresh = maybe_refresh_stats($config, true);

if (!empty($refresh['error'])) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => $refresh['error'],
        'refresh' => $refresh,
    ]);
    exit;
}

if (!empty($refresh['in_progress'])) {
    http_response_code(202);
    echo json_encode([
        'status' => 'in_progress',
        'message' => 'Refresh is already running',
        'refresh' => $refresh,
    ]);
    exit;
}

echo json_encode([
    'status' => $refresh['summary']['status'] ?? 'success',
    'debug_sync' => is_sync_debug_enabled($config),
    'refresh' => $refresh,
]);
