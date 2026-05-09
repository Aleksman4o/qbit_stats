<?php

header('Content-Type: application/json');

$config = require __DIR__ . '/config.php';
require_once __DIR__ . '/data_functions.php';

$hours = isset($_GET['hours']) ? (int)$_GET['hours'] : 0;
$db = open_database($config);
$data = get_history_data($db, $config, $hours);
$db->close();

echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
