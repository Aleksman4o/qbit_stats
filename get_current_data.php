<?php

header('Content-Type: application/json');

$config = require __DIR__ . '/config.php';
require_once __DIR__ . '/collector.php';
require_once __DIR__ . '/data_functions.php';

$forceRefresh = isset($_GET['force']) && $_GET['force'] === '1';
$refresh = maybe_refresh_stats($config, $forceRefresh);

$db = open_database($config);
$data = get_current_data($db, $config, $refresh);
$db->close();

if (!empty($refresh['error']) && empty($data['last_update'])) {
    http_response_code(503);
}

echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
