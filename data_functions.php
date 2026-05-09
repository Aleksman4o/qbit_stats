<?php

require_once __DIR__ . '/bootstrap.php';

function get_current_data(SQLite3 $db, array $config, array $refreshMeta = []): array
{
    $settings = get_monitor_settings($config);
    $latestUpdate = get_latest_update($db);
    $instances = [];

    foreach ($config['instances'] as $instance) {
        $instanceStmt = $db->prepare('SELECT
            name,
            dl_speed,
            up_speed,
            dl_session,
            up_session,
            last_update,
            status,
            last_error,
            last_attempt,
            last_success
            FROM instances
            WHERE name = :name');
        $instanceStmt->bindValue(':name', $instance['name'], SQLITE3_TEXT);
        $row = $instanceStmt->execute()->fetchArray(SQLITE3_ASSOC);

        $instances[] = $row ?: [
            'name' => $instance['name'],
            'dl_speed' => 0,
            'up_speed' => 0,
            'dl_session' => 0,
            'up_session' => 0,
            'last_update' => null,
            'status' => 'unknown',
            'last_error' => null,
            'last_attempt' => null,
            'last_success' => null,
        ];
    }

    $categories = [];
    $categoryResult = $db->query('SELECT
        category,
        GROUP_CONCAT(instance_name, ", ") AS instances,
        COUNT(*) AS instances_count,
        SUM(active_torrents) AS active_torrents,
        SUM(dl_speed) AS dl_speed,
        SUM(up_speed) AS up_speed,
        SUM(total_size) AS total_size,
        SUM(uploaded_session) AS uploaded_session,
        SUM(uploaded_total) AS uploaded_total
        FROM categories
        GROUP BY category
        ORDER BY up_speed DESC, dl_speed DESC, category ASC');

    while ($row = $categoryResult->fetchArray(SQLITE3_ASSOC)) {
        $categories[] = $row;
    }

    $okCount = 0;
    $errorCount = 0;

    foreach ($instances as $instance) {
        if (($instance['status'] ?? 'unknown') === 'ok') {
            $okCount++;
        } elseif (($instance['status'] ?? 'unknown') === 'error') {
            $errorCount++;
        }
    }

    return [
        'instances' => $instances,
        'categories' => $categories,
        'last_update' => $latestUpdate,
        'meta' => [
            'instance_count' => count($instances),
            'ok_count' => $okCount,
            'error_count' => $errorCount,
            'is_stale' => is_refresh_needed($db, $config),
            'dashboard_poll_interval_seconds' => $settings['dashboard_poll_interval_seconds'],
            'history_hours_default' => $settings['history_hours_default'],
            'history_hours_options' => $settings['history_hours_options'],
            'refresh_ttl_seconds' => $settings['refresh_ttl_seconds'],
            'refresh' => $refreshMeta,
        ],
    ];
}

function get_history_data(SQLite3 $db, array $config, int $hours): array
{
    $normalizedHours = normalize_history_hours($config, $hours);
    $cutoff = date('Y-m-d H:i:s', time() - ($normalizedHours * 3600));

    $stmt = $db->prepare('SELECT
        timestamp,
        instance_name,
        dl_speed,
        up_speed
        FROM speed_history
        WHERE timestamp >= :cutoff
        ORDER BY timestamp ASC, instance_name ASC');
    $stmt->bindValue(':cutoff', $cutoff, SQLITE3_TEXT);

    $result = $stmt->execute();
    $rows = [];

    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $rows[] = $row;
    }

    return [
        'hours' => $normalizedHours,
        'data' => $rows,
    ];
}

function get_category_history(SQLite3 $db, string $timestamp): array
{
    $stmt = $db->prepare('SELECT
        category,
        GROUP_CONCAT(instance_name, ", ") AS instances,
        COUNT(*) AS instances_count,
        SUM(active_torrents) AS active_torrents,
        SUM(dl_speed) AS dl_speed,
        SUM(up_speed) AS up_speed,
        SUM(total_size) AS total_size,
        SUM(uploaded_session) AS uploaded_session,
        SUM(uploaded_total) AS uploaded_total
        FROM category_history
        WHERE timestamp = :timestamp
        GROUP BY category
        ORDER BY up_speed DESC, dl_speed DESC, category ASC');
    $stmt->bindValue(':timestamp', $timestamp, SQLITE3_TEXT);

    $result = $stmt->execute();
    $rows = [];

    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $rows[] = $row;
    }

    return $rows;
}
