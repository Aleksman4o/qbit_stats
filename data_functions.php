<?php

require_once __DIR__ . '/bootstrap.php';

function get_current_data(SQLite3 $db, array $config, array $refreshMeta = []): array
{
    $settings = get_monitor_settings($config);
    $latestUpdate = get_latest_update($db, $config);
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
    $instanceNames = get_configured_instance_names($config);

    if (!empty($instanceNames)) {
        $placeholders = create_sqlite_placeholders($instanceNames, 'current_instance_');
        $categoryStmt = $db->prepare('SELECT
            categories.category,
            GROUP_CONCAT(categories.instance_name, ", ") AS instances,
            COUNT(*) AS instances_count,
            SUM(categories.active_torrents) AS active_torrents,
            SUM(categories.dl_speed) AS dl_speed,
            SUM(categories.up_speed) AS up_speed,
            SUM(categories.total_size) AS total_size,
            SUM(categories.uploaded_session) AS uploaded_session,
            SUM(categories.uploaded_total) AS uploaded_total
            FROM categories
            INNER JOIN instances ON instances.name = categories.instance_name
            WHERE categories.instance_name IN (' . implode(', ', $placeholders) . ')
              AND instances.status = :ok_status
            GROUP BY categories.category
            ORDER BY up_speed DESC, dl_speed DESC, categories.category ASC');
        bind_sqlite_text_values($categoryStmt, $instanceNames, 'current_instance_');
        $categoryStmt->bindValue(':ok_status', 'ok', SQLITE3_TEXT);
        $categoryResult = $categoryStmt->execute();

        while ($row = $categoryResult->fetchArray(SQLITE3_ASSOC)) {
            $categories[] = $row;
        }
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

function get_history_bucket_seconds(int $hours): int
{
    if ($hours <= 1) {
        return 30;
    }

    if ($hours <= 6) {
        return 60;
    }

    return 300;
}

function get_sampled_history_timestamps(
    SQLite3 $db,
    array $instanceNames,
    string $cutoff,
    int $bucketSeconds
): array {
    if (empty($instanceNames)) {
        return [];
    }

    $placeholders = create_sqlite_placeholders($instanceNames, 'sampled_history_instance_');
    $stmt = $db->prepare('SELECT MAX(timestamp) AS timestamp
        FROM speed_history
        WHERE timestamp >= :cutoff
          AND instance_name IN (' . implode(', ', $placeholders) . ')
        GROUP BY CAST(strftime(\'%s\', timestamp) AS INTEGER) / :bucket_seconds
        ORDER BY timestamp ASC');
    $stmt->bindValue(':cutoff', $cutoff, SQLITE3_TEXT);
    $stmt->bindValue(':bucket_seconds', $bucketSeconds, SQLITE3_INTEGER);
    bind_sqlite_text_values($stmt, $instanceNames, 'sampled_history_instance_');

    $result = $stmt->execute();
    $timestamps = [];

    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $timestamps[] = $row['timestamp'];
    }

    return $timestamps;
}

function get_history_data(SQLite3 $db, array $config, int $hours): array
{
    $normalizedHours = normalize_history_hours($config, $hours);
    $cutoff = date('Y-m-d H:i:s', time() - ($normalizedHours * 3600));
    $instanceNames = get_configured_instance_names($config);
    $rows = [];
    $categoryUploadHistory = [
        'timestamps' => [],
        'series' => [],
    ];

    if (empty($instanceNames)) {
        return [
            'hours' => $normalizedHours,
            'data' => $rows,
            'category_upload_history' => $categoryUploadHistory,
        ];
    }

    $timestamps = get_sampled_history_timestamps(
        $db,
        $instanceNames,
        $cutoff,
        get_history_bucket_seconds($normalizedHours)
    );

    if (empty($timestamps)) {
        return [
            'hours' => $normalizedHours,
            'data' => $rows,
            'category_upload_history' => $categoryUploadHistory,
        ];
    }

    $categoryUploadHistory['timestamps'] = $timestamps;
    $timestampIndexes = array_flip($timestamps);

    $placeholders = create_sqlite_placeholders($instanceNames, 'history_instance_');
    $timestampPlaceholders = create_sqlite_placeholders($timestamps, 'history_timestamp_');
    $stmt = $db->prepare('SELECT
        timestamp,
        instance_name,
        dl_speed,
        up_speed
        FROM speed_history
        WHERE instance_name IN (' . implode(', ', $placeholders) . ')
          AND timestamp IN (' . implode(', ', $timestampPlaceholders) . ')
        ORDER BY timestamp ASC, instance_name ASC');
    bind_sqlite_text_values($stmt, $instanceNames, 'history_instance_');
    bind_sqlite_text_values($stmt, $timestamps, 'history_timestamp_');

    $result = $stmt->execute();

    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $rows[] = $row;
    }

    $categoryPlaceholders = create_sqlite_placeholders($instanceNames, 'category_upload_instance_');
    $categoryTimestampPlaceholders = create_sqlite_placeholders($timestamps, 'category_upload_timestamp_');
    $categoryStmt = $db->prepare('
        SELECT
            category_history.timestamp,
            category_history.category,
            SUM(category_history.up_speed) AS up_speed
        FROM category_history
        WHERE category_history.instance_name IN (' . implode(', ', $categoryPlaceholders) . ')
          AND category_history.timestamp IN (' . implode(', ', $categoryTimestampPlaceholders) . ')
        GROUP BY category_history.timestamp, category_history.category
        ORDER BY category_history.timestamp ASC, category_history.category ASC');
    bind_sqlite_text_values($categoryStmt, $instanceNames, 'category_upload_instance_');
    bind_sqlite_text_values($categoryStmt, $timestamps, 'category_upload_timestamp_');
    $categoryResult = $categoryStmt->execute();
    $categoryPoints = [];
    $categoryTotals = [];

    while ($row = $categoryResult->fetchArray(SQLITE3_ASSOC)) {
        $timestamp = $row['timestamp'];
        $category = $row['category'];
        $upSpeed = (int)$row['up_speed'];

        $categoryPoints[$category][$timestampIndexes[$timestamp]] = $upSpeed;
        $categoryTotals[$category] = ($categoryTotals[$category] ?? 0) + $upSpeed;
    }

    uksort($categoryPoints, function (string $left, string $right) use ($categoryTotals): int {
        $totalComparison = ($categoryTotals[$right] ?? 0) <=> ($categoryTotals[$left] ?? 0);

        return $totalComparison !== 0 ? $totalComparison : strnatcasecmp($left, $right);
    });

    $pointCount = count($categoryUploadHistory['timestamps']);
    foreach ($categoryPoints as $category => $points) {
        $values = array_fill(0, $pointCount, 0);

        foreach ($points as $index => $upSpeed) {
            $values[$index] = $upSpeed;
        }

        $categoryUploadHistory['series'][] = [
            'category' => $category,
            'data' => $values,
        ];
    }

    return [
        'hours' => $normalizedHours,
        'data' => $rows,
        'category_upload_history' => $categoryUploadHistory,
    ];
}

function get_category_history(SQLite3 $db, array $config, string $timestamp): array
{
    $instanceNames = get_configured_instance_names($config);
    if (empty($instanceNames)) {
        return [];
    }

    $placeholders = create_sqlite_placeholders($instanceNames, 'category_history_instance_');
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
          AND instance_name IN (' . implode(', ', $placeholders) . ')
        GROUP BY category
        ORDER BY up_speed DESC, dl_speed DESC, category ASC');
    $stmt->bindValue(':timestamp', $timestamp, SQLITE3_TEXT);
    bind_sqlite_text_values($stmt, $instanceNames, 'category_history_instance_');

    $result = $stmt->execute();
    $rows = [];

    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $rows[] = $row;
    }

    return $rows;
}
