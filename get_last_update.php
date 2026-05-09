<?php

header('Content-Type: application/json');

$config = require __DIR__ . '/config.php';
require_once __DIR__ . '/collector.php';
require_once __DIR__ . '/bootstrap.php';

$refresh = maybe_refresh_stats($config, false);

$db = open_database($config);
$lastUpdate = get_latest_update($db);
$isStale = is_refresh_needed($db, $config);
$db->close();

if (!empty($refresh['error']) && $lastUpdate === null) {
    http_response_code(503);
}

echo json_encode([
    'last_update' => $lastUpdate,
    'is_stale' => $isStale,
    'refresh' => $refresh,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
