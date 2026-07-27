<?php

define('QBIT_STATS_CONFIG_LIBRARY_ONLY', true);
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../collector.php';
require_once __DIR__ . '/../data_functions.php';

function assert_same($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(sprintf(
            '%s. Expected %s, got %s',
            $message,
            var_export($expected, true),
            var_export($actual, true)
        ));
    }
}

function remove_test_database(string $path): void
{
    foreach ([$path, $path . '-shm', $path . '-wal'] as $candidate) {
        if (is_file($candidate)) {
            unlink($candidate);
        }
    }
}

$dbPath = tempnam(sys_get_temp_dir(), 'qbit_stats_test_');
if ($dbPath === false) {
    throw new RuntimeException('Unable to create a temporary database path');
}

$iniPath = tempnam(sys_get_temp_dir(), 'qbit_stats_ini_test_');
if ($iniPath === false) {
    throw new RuntimeException('Unable to create a temporary config path');
}

$config = [
    'db_path' => $dbPath,
    'instances' => [
        ['name' => 'active'],
    ],
    'settings' => [
        'history_hours_default' => 6,
        'history_hours_options' => [1, 6, 24],
    ],
];

try {
    file_put_contents($iniPath, <<<'INI'
[other]
user_version="3"
qt="5"

[torrent-client-1]
id="39"
client="qbittorrent"
hostname="one.example"
port="8080"
comment="one"

[torrent-client-2]
id="40"
client="transmission"
hostname="transmission.example"
port="9091"
comment="transmission"

[torrent-client-3]
id="41"
client="qbittorrent"
hostname="three.example"
port="8080"
comment="three"

[torrent-client-4]
id="42"
client="qbittorrent"
hostname="excluded.example"
port="8080"
comment="excluded"
exclude="1"

[torrent-client-5]
id="43"
client="utorrent"
hostname="utorrent.example"
port="8080"
comment="utorrent"

[torrent-client-6]
id="44"
client="qbittorrent"
hostname="stale.example"
port="8080"
comment="stale"
INI);
    $parsedClientsConfig = parseClientsConfig($iniPath);
    assert_same(
        ['one', 'three'],
        array_column($parsedClientsConfig['instances'], 'name'),
        'Only active, non-excluded qBittorrent sections within [other] qt must be configured'
    );
    assert_same(
        [39, 41],
        array_column($parsedClientsConfig['instances'], 'id'),
        'Torrent client IDs must not be limited by the [other] qt section count'
    );
    assert_same(20, get_monitor_settings([])['parallel_sync_limit'], 'The default parallel request limit must support larger installations');

    $db = open_database($config);
    assert_same(30, get_history_bucket_seconds(1), 'One-hour history must keep 30-second points');
    assert_same(60, get_history_bucket_seconds(6), 'Six-hour history must use one-minute points');
    assert_same(300, get_history_bucket_seconds(24), 'Twenty-four-hour history must use five-minute points');

    $db->exec("INSERT INTO instances
        (name, torrent_count, last_update, status, last_attempt, last_success)
        VALUES ('active', 3, '2026-01-01 00:00:00', 'ok', '2026-01-01 00:00:00', '2026-01-01 00:00:00')");
    $db->exec("INSERT INTO torrents
        (instance_name, hash, category, state, dlspeed, upspeed, size, uploaded_total, uploaded_session)
        VALUES
        ('active', 'h1', 'A', 'uploading', 10, 20, 100, 200, 30),
        ('active', 'h2', 'A', 'stalledUP', 0, 5, 300, 400, 60),
        ('active', 'h3', 'B', 'downloading', 40, 50, 500, 600, 70)");
    $db->exec("INSERT INTO categories
        (instance_name, category, active_torrents, dl_speed, up_speed, total_size, uploaded_session, uploaded_total, last_update)
        VALUES ('active', 'broken', 0, 0, 0, 0, 0, 0, '2026-01-01 00:00:00')");
    $db->exec("DELETE FROM schema_migrations
        WHERE name = '20260726_rebuild_categories_after_instance_errors'");
    $db->close();

    $db = open_database($config);
    $categoryA = $db->querySingle("SELECT json_object(
        'active_torrents', active_torrents,
        'dl_speed', dl_speed,
        'up_speed', up_speed,
        'total_size', total_size,
        'uploaded_session', uploaded_session,
        'uploaded_total', uploaded_total
    ) FROM categories WHERE instance_name = 'active' AND category = 'A'");
    assert_same(
        [
            'active_torrents' => 1,
            'dl_speed' => 10,
            'up_speed' => 25,
            'total_size' => 400,
            'uploaded_session' => 90,
            'uploaded_total' => 600,
        ],
        json_decode($categoryA, true),
        'The migration must rebuild category aggregates from torrents'
    );
    assert_same(2, (int)$db->querySingle("SELECT COUNT(*) FROM categories WHERE instance_name = 'active'"), 'The migration must remove corrupt category rows');

    $instance = ['name' => 'active'];
    persist_instance_error($db, $instance, '2026-01-01 00:01:00', 'temporary outage');
    assert_same(2, (int)$db->querySingle("SELECT COUNT(*) FROM categories WHERE instance_name = 'active'"), 'A temporary error must preserve category aggregates');
    assert_same([], get_current_data($db, $config)['categories'], 'Categories from an unavailable instance must be hidden from the live view');

    persist_instance_snapshot($db, $instance, '2026-01-01 00:02:00', [
        'stored_sid' => 'SID=x',
        'sid_cookie' => 'SID=x',
        'transfer' => [],
        'server_state' => [],
        'torrents_data' => [
            'h1' => ['dlspeed' => 11],
        ],
        'torrents_removed' => [],
        'sync' => [
            'full_update' => false,
            'rid_before' => 1,
            'rid_after' => 2,
        ],
    ]);

    assert_same(411, (int)$db->querySingle("SELECT total_size + dl_speed FROM categories WHERE instance_name = 'active' AND category = 'A'"), 'Incremental recovery must use the preserved aggregate');
    assert_same(2, (int)$db->querySingle("SELECT COUNT(*) FROM categories WHERE instance_name = 'active'"), 'Incremental recovery must not lose untouched categories');

    $historyTimestamp = date('Y-m-d H:i:s');
    $removedUpdate = date('Y-m-d H:i:s', time() + 3600);
    $insertRemoved = $db->prepare("INSERT INTO instances
        (name, torrent_count, last_update, status, last_attempt, last_success)
        VALUES ('removed', 1, :last_update, 'ok', :last_update, :last_update)");
    $insertRemoved->bindValue(':last_update', $removedUpdate, SQLITE3_TEXT);
    $insertRemoved->execute();
    $db->exec("INSERT INTO categories
        (instance_name, category, active_torrents, dl_speed, up_speed, total_size, uploaded_session, uploaded_total, last_update)
        VALUES ('removed', 'ghost', 1, 1, 1, 1, 1, 1, '2026-01-01 00:00:00')");

    $speedHistory = $db->prepare('INSERT INTO speed_history (instance_name, dl_speed, up_speed, timestamp) VALUES (:instance_name, 1, 1, :timestamp)');
    foreach (['active', 'removed'] as $instanceName) {
        $speedHistory->bindValue(':instance_name', $instanceName, SQLITE3_TEXT);
        $speedHistory->bindValue(':timestamp', $historyTimestamp, SQLITE3_TEXT);
        $speedHistory->execute();
    }

    $categoryHistory = $db->prepare("INSERT INTO category_history
        (instance_name, category, active_torrents, dl_speed, up_speed, total_size, uploaded_session, uploaded_total, timestamp)
        VALUES (:instance_name, :category, 1, 1, 1, 1, 1, 1, :timestamp)");
    foreach (['active' => 'A', 'removed' => 'ghost'] as $instanceName => $category) {
        $categoryHistory->bindValue(':instance_name', $instanceName, SQLITE3_TEXT);
        $categoryHistory->bindValue(':category', $category, SQLITE3_TEXT);
        $categoryHistory->bindValue(':timestamp', $historyTimestamp, SQLITE3_TEXT);
        $categoryHistory->execute();
    }

    $currentData = get_current_data($db, $config);
    assert_same(false, in_array('ghost', array_column($currentData['categories'], 'category'), true), 'Removed instances must be excluded from current categories');
    assert_same('2026-01-01 00:02:00', $currentData['last_update'], 'Removed instances must not affect the latest configured update');

    $history = get_history_data($db, $config, 6);
    assert_same(['active'], array_values(array_unique(array_column($history['data'], 'instance_name'))), 'Removed instances must be excluded from speed history');
    assert_same(['A'], array_column($history['category_upload_history']['series'], 'category'), 'Removed instances must be excluded from category upload history');

    $categorySnapshot = get_category_history($db, $config, $historyTimestamp);
    assert_same(['A'], array_column($categorySnapshot, 'category'), 'Removed instances must be excluded from category history');

    for ($index = 1; $index <= 13; $index++) {
        $categoryHistory->bindValue(':instance_name', 'active', SQLITE3_TEXT);
        $categoryHistory->bindValue(':category', 'extra-' . $index, SQLITE3_TEXT);
        $categoryHistory->bindValue(':timestamp', $historyTimestamp, SQLITE3_TEXT);
        $categoryHistory->execute();
    }

    $allCategoryHistory = get_history_data($db, $config, 6)['category_upload_history'];
    assert_same(14, count($allCategoryHistory['series']), 'Category upload history must not limit the number of categories');
    foreach ($allCategoryHistory['series'] as $series) {
        assert_same(count($allCategoryHistory['timestamps']), count($series['data']), 'Every category series must align with the shared timeline');
    }

    $bucketStart = intdiv(time(), 300) * 300 - 600;
    $sampleTimestamps = [
        date('Y-m-d H:i:s', $bucketStart + 30),
        date('Y-m-d H:i:s', $bucketStart + 120),
        date('Y-m-d H:i:s', $bucketStart + 330),
    ];
    $sampleSpeedHistory = $db->prepare('INSERT INTO speed_history
        (instance_name, dl_speed, up_speed, timestamp)
        VALUES (:instance_name, :dl_speed, :up_speed, :timestamp)');
    $sampleCategoryHistory = $db->prepare('INSERT INTO category_history
        (instance_name, category, active_torrents, dl_speed, up_speed, total_size, uploaded_session, uploaded_total, timestamp)
        VALUES (:instance_name, :category, 1, 0, :up_speed, 1, 1, 1, :timestamp)');

    foreach ($sampleTimestamps as $index => $timestamp) {
        $speed = $index + 1;
        $sampleSpeedHistory->bindValue(':instance_name', 'active', SQLITE3_TEXT);
        $sampleSpeedHistory->bindValue(':dl_speed', $speed, SQLITE3_INTEGER);
        $sampleSpeedHistory->bindValue(':up_speed', $speed, SQLITE3_INTEGER);
        $sampleSpeedHistory->bindValue(':timestamp', $timestamp, SQLITE3_TEXT);
        $sampleSpeedHistory->execute();

        $sampleCategoryHistory->bindValue(':instance_name', 'active', SQLITE3_TEXT);
        $sampleCategoryHistory->bindValue(':category', 'sampled', SQLITE3_TEXT);
        $sampleCategoryHistory->bindValue(':up_speed', $speed, SQLITE3_INTEGER);
        $sampleCategoryHistory->bindValue(':timestamp', $timestamp, SQLITE3_TEXT);
        $sampleCategoryHistory->execute();
    }

    $sampledHistory = get_history_data($db, $config, 24);
    $sampledSpeedTimestamps = array_values(array_unique(array_column($sampledHistory['data'], 'timestamp')));
    assert_same(false, in_array($sampleTimestamps[0], $sampledSpeedTimestamps, true), 'An older point in a five-minute bucket must be omitted');
    assert_same(true, in_array($sampleTimestamps[1], $sampledSpeedTimestamps, true), 'The latest real point in a five-minute bucket must be retained');
    assert_same(true, in_array($sampleTimestamps[2], $sampledSpeedTimestamps, true), 'The latest point from the next bucket must be retained');
    assert_same(
        $sampledSpeedTimestamps,
        $sampledHistory['category_upload_history']['timestamps'],
        'Instance and category charts must use the same sampled timestamps'
    );

    $newestOpenBucketTimestamp = date('Y-m-d H:i:s', $bucketStart + 360);
    $sampleSpeedHistory->bindValue(':instance_name', 'active', SQLITE3_TEXT);
    $sampleSpeedHistory->bindValue(':dl_speed', 4, SQLITE3_INTEGER);
    $sampleSpeedHistory->bindValue(':up_speed', 4, SQLITE3_INTEGER);
    $sampleSpeedHistory->bindValue(':timestamp', $newestOpenBucketTimestamp, SQLITE3_TEXT);
    $sampleSpeedHistory->execute();
    $sampleCategoryHistory->bindValue(':instance_name', 'active', SQLITE3_TEXT);
    $sampleCategoryHistory->bindValue(':category', 'sampled', SQLITE3_TEXT);
    $sampleCategoryHistory->bindValue(':up_speed', 4, SQLITE3_INTEGER);
    $sampleCategoryHistory->bindValue(':timestamp', $newestOpenBucketTimestamp, SQLITE3_TEXT);
    $sampleCategoryHistory->execute();

    $updatedSampledHistory = get_history_data($db, $config, 24);
    $updatedSpeedTimestamps = array_values(array_unique(array_column($updatedSampledHistory['data'], 'timestamp')));
    assert_same(false, in_array($sampleTimestamps[2], $updatedSpeedTimestamps, true), 'The previous point from the open bucket must be replaced');
    assert_same(true, in_array($newestOpenBucketTimestamp, $updatedSpeedTimestamps, true), 'A fresher point must update the open bucket');
    assert_same(
        $updatedSpeedTimestamps,
        $updatedSampledHistory['category_upload_history']['timestamps'],
        'Both chart timelines must remain aligned after a real-time update'
    );

    $db->close();
    echo "All regression tests passed\n";
} finally {
    if (isset($db) && $db instanceof SQLite3) {
        $db->close();
    }

    remove_test_database($dbPath);
    if (is_file($iniPath)) {
        unlink($iniPath);
    }
}
