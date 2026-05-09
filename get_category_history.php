<?php

header('Content-Type: application/json');

$config = require __DIR__ . '/config.php';
require_once __DIR__ . '/data_functions.php';

try {
    if (!isset($_GET['timestamp'])) {
        throw new InvalidArgumentException('Timestamp parameter is missing');
    }

    $timestamp = $_GET['timestamp'];
    if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $timestamp)) {
        throw new InvalidArgumentException('Invalid timestamp format');
    }

    $db = open_database($config);
    $data = get_category_history($db, $timestamp);
    $db->close();

    echo json_encode([
        'timestamp' => $timestamp,
        'data' => $data,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
}
