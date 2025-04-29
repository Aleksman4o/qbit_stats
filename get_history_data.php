<?php
header('Content-Type: application/json');
$config = require __DIR__.'/config.php';

$db = new SQLite3($config['db_path']);
$hours = isset($_GET['hours']) ? (int)$_GET['hours'] : 6;
$cutoff = date('Y-m-d H:i:s', time() - $hours * 3600);

$result = $db->query("SELECT 
    timestamp,
    instance_name,
    dl_speed,
    up_speed
    FROM speed_history
    WHERE timestamp >= '$cutoff'
    ORDER BY timestamp ASC");

$data = [];
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    $data[] = $row;
}

echo json_encode($data);