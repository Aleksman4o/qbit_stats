<?php

function get_monitor_settings(array $config): array
{
    $defaults = [
        'refresh_ttl_seconds' => 60,
        'dashboard_poll_interval_seconds' => 30,
        'history_hours_default' => 6,
        'history_hours_options' => [1, 6, 24],
        'history_retention_days' => 7,
        'connect_timeout_seconds' => 5,
        'request_timeout_seconds' => 20,
        'parallel_sync_limit' => 20,
        'debug_sync' => false,
        'lock_path' => __DIR__ . '/qbittorrent_stats.lock',
    ];

    $settings = $config['settings'] ?? [];
    $merged = array_merge($defaults, $settings);

    $hoursOptions = array_values(array_unique(array_map('intval', $merged['history_hours_options'])));
    sort($hoursOptions);

    if (empty($hoursOptions)) {
        $hoursOptions = $defaults['history_hours_options'];
    }

    $defaultHours = (int)$merged['history_hours_default'];
    if (!in_array($defaultHours, $hoursOptions, true)) {
        $hoursOptions[] = $defaultHours;
        sort($hoursOptions);
    }

    $merged['history_hours_default'] = $defaultHours;
    $merged['history_hours_options'] = $hoursOptions;
    $merged['refresh_ttl_seconds'] = max(5, (int)$merged['refresh_ttl_seconds']);
    $merged['dashboard_poll_interval_seconds'] = max(5, (int)$merged['dashboard_poll_interval_seconds']);
    $merged['history_retention_days'] = max(1, (int)$merged['history_retention_days']);
    $merged['connect_timeout_seconds'] = max(1, (int)$merged['connect_timeout_seconds']);
    $merged['request_timeout_seconds'] = max($merged['connect_timeout_seconds'], (int)$merged['request_timeout_seconds']);
    $merged['parallel_sync_limit'] = max(1, (int)$merged['parallel_sync_limit']);
    $merged['debug_sync'] = (bool)$merged['debug_sync'];

    return $merged;
}

function open_database(array $config): SQLite3
{
    $db = new SQLite3($config['db_path']);
    $db->enableExceptions(true);
    $db->busyTimeout(5000);
    $db->exec('PRAGMA journal_mode=WAL');
    $db->exec('PRAGMA synchronous=NORMAL');
    $db->exec('PRAGMA temp_store=MEMORY');
    $db->exec('PRAGMA foreign_keys=ON');

    ensure_schema($db);

    return $db;
}

function ensure_schema(SQLite3 $db): void
{
    $db->exec('CREATE TABLE IF NOT EXISTS instances (
        name TEXT PRIMARY KEY,
        dl_speed INTEGER DEFAULT 0,
        up_speed INTEGER DEFAULT 0,
        dl_session INTEGER DEFAULT 0,
        up_session INTEGER DEFAULT 0,
        torrent_count INTEGER DEFAULT 0,
        last_update DATETIME,
        status TEXT DEFAULT "unknown",
        last_error TEXT,
        last_attempt DATETIME,
        last_success DATETIME
    )');

    $db->exec('CREATE TABLE IF NOT EXISTS categories (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        instance_name TEXT NOT NULL,
        category TEXT NOT NULL,
        active_torrents INTEGER DEFAULT 0,
        dl_speed INTEGER DEFAULT 0,
        up_speed INTEGER DEFAULT 0,
        total_size INTEGER DEFAULT 0,
        uploaded_session INTEGER DEFAULT 0,
        uploaded_total INTEGER DEFAULT 0,
        last_update DATETIME,
        UNIQUE(instance_name, category)
    )');

    $db->exec('CREATE TABLE IF NOT EXISTS speed_history (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        instance_name TEXT NOT NULL,
        dl_speed INTEGER DEFAULT 0,
        up_speed INTEGER DEFAULT 0,
        timestamp DATETIME NOT NULL
    )');

    $db->exec('CREATE TABLE IF NOT EXISTS category_history (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        instance_name TEXT NOT NULL,
        category TEXT NOT NULL,
        active_torrents INTEGER DEFAULT 0,
        dl_speed INTEGER DEFAULT 0,
        up_speed INTEGER DEFAULT 0,
        total_size INTEGER DEFAULT 0,
        uploaded_session INTEGER DEFAULT 0,
        uploaded_total INTEGER DEFAULT 0,
        timestamp DATETIME NOT NULL
    )');

    $db->exec('CREATE TABLE IF NOT EXISTS torrents (
        instance_name TEXT NOT NULL,
        hash TEXT NOT NULL,
        category TEXT,
        state TEXT,
        dlspeed INTEGER DEFAULT 0,
        upspeed INTEGER DEFAULT 0,
        size INTEGER DEFAULT 0,
        uploaded_total INTEGER DEFAULT 0,
        uploaded_session INTEGER DEFAULT 0,
        PRIMARY KEY (instance_name, hash)
    )');

    $db->exec('CREATE TABLE IF NOT EXISTS instance_sync_state (
        instance_name TEXT PRIMARY KEY,
        last_rid INTEGER DEFAULT 0
    )');

    $db->exec('CREATE TABLE IF NOT EXISTS instance_sessions (
        instance_name TEXT PRIMARY KEY,
        sid TEXT,
        last_login DATETIME
    )');

    $db->exec('CREATE TABLE IF NOT EXISTS schema_migrations (
        name TEXT PRIMARY KEY,
        applied_at DATETIME NOT NULL
    )');

    ensure_table_columns($db, 'instances', [
        'torrent_count' => 'INTEGER DEFAULT 0',
        'status' => 'TEXT DEFAULT "unknown"',
        'last_error' => 'TEXT',
        'last_attempt' => 'DATETIME',
        'last_success' => 'DATETIME',
    ]);

    ensure_table_columns($db, 'categories', [
        'uploaded_total' => 'INTEGER DEFAULT 0',
    ]);

    ensure_table_columns($db, 'category_history', [
        'uploaded_total' => 'INTEGER DEFAULT 0',
    ]);

    $db->exec("UPDATE instances SET status = 'unknown' WHERE status IS NULL OR status = ''");
    $db->exec('UPDATE instances
        SET torrent_count = (
            SELECT COUNT(*)
            FROM torrents
            WHERE torrents.instance_name = instances.name
        )
        WHERE torrent_count IS NULL');
    $db->exec('UPDATE instances SET last_success = last_update WHERE last_success IS NULL AND last_update IS NOT NULL');
    $db->exec('UPDATE instances SET last_attempt = last_update WHERE last_attempt IS NULL AND last_update IS NOT NULL');

    run_schema_migrations($db);
    ensure_indexes($db);
}

function run_schema_migrations(SQLite3 $db): void
{
    $migrationName = '20260726_rebuild_categories_after_instance_errors';
    $migrationCheck = $db->prepare('SELECT 1 FROM schema_migrations WHERE name = :name');
    $migrationCheck->bindValue(':name', $migrationName, SQLITE3_TEXT);

    if ($migrationCheck->execute()->fetchArray(SQLITE3_NUM)) {
        return;
    }

    $db->exec('BEGIN IMMEDIATE');

    try {
        $migrationCheck = $db->prepare('SELECT 1 FROM schema_migrations WHERE name = :name');
        $migrationCheck->bindValue(':name', $migrationName, SQLITE3_TEXT);

        if (!$migrationCheck->execute()->fetchArray(SQLITE3_NUM)) {
            rebuild_all_categories_from_torrents($db);

            $insertMigration = $db->prepare('INSERT INTO schema_migrations (name, applied_at) VALUES (:name, :applied_at)');
            $insertMigration->bindValue(':name', $migrationName, SQLITE3_TEXT);
            $insertMigration->bindValue(':applied_at', date('Y-m-d H:i:s'), SQLITE3_TEXT);
            $insertMigration->execute();
        }

        $db->exec('COMMIT');
    } catch (Throwable $e) {
        $db->exec('ROLLBACK');
        throw $e;
    }
}

function rebuild_all_categories_from_torrents(SQLite3 $db): void
{
    $db->exec('DELETE FROM categories');
    $db->exec("INSERT INTO categories
        (instance_name, category, active_torrents, dl_speed, up_speed, total_size, uploaded_session, uploaded_total, last_update)
        SELECT
            torrents.instance_name,
            CASE
                WHEN torrents.category IS NULL OR torrents.category = '' THEN 'Uncategorized'
                ELSE torrents.category
            END AS normalized_category,
            SUM(CASE WHEN torrents.state IN ('uploading', 'downloading') THEN 1 ELSE 0 END),
            SUM(torrents.dlspeed),
            SUM(torrents.upspeed),
            SUM(torrents.size),
            SUM(torrents.uploaded_session),
            SUM(torrents.uploaded_total),
            MAX(instances.last_update)
        FROM torrents
        LEFT JOIN instances ON instances.name = torrents.instance_name
        GROUP BY torrents.instance_name, normalized_category");
}

function ensure_table_columns(SQLite3 $db, string $table, array $columns): void
{
    $existingColumns = get_table_columns($db, $table);

    foreach ($columns as $column => $definition) {
        if (isset($existingColumns[$column])) {
            continue;
        }

        $db->exec(sprintf(
            'ALTER TABLE %s ADD COLUMN %s %s',
            $table,
            $column,
            $definition
        ));
    }
}

function get_table_columns(SQLite3 $db, string $table): array
{
    $columns = [];
    $result = $db->query(sprintf('PRAGMA table_info(%s)', $table));

    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $columns[$row['name']] = $row;
    }

    return $columns;
}

function ensure_indexes(SQLite3 $db): void
{
    $db->exec('CREATE INDEX IF NOT EXISTS idx_speed_history_timestamp ON speed_history (timestamp)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_speed_history_instance_timestamp ON speed_history (instance_name, timestamp)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_category_history_timestamp ON category_history (timestamp)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_category_history_timestamp_category ON category_history (timestamp, category)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_categories_category ON categories (category)');
    $db->exec('DROP INDEX IF EXISTS idx_torrents_instance');
}

function get_configured_instance_names(array $config): array
{
    $names = [];

    foreach ($config['instances'] ?? [] as $instance) {
        $name = (string)($instance['name'] ?? '');
        if ($name !== '') {
            $names[$name] = true;
        }
    }

    return array_keys($names);
}

function create_sqlite_placeholders(array $values, string $prefix): array
{
    $placeholders = [];

    foreach (array_keys($values) as $index) {
        $placeholders[] = ':' . $prefix . $index;
    }

    return $placeholders;
}

function bind_sqlite_text_values(SQLite3Stmt $stmt, array $values, string $prefix): void
{
    foreach (array_values($values) as $index => $value) {
        $stmt->bindValue(':' . $prefix . $index, (string)$value, SQLITE3_TEXT);
    }
}

function get_latest_update(SQLite3 $db, array $config): ?string
{
    $instanceNames = get_configured_instance_names($config);
    if (empty($instanceNames)) {
        return null;
    }

    $placeholders = create_sqlite_placeholders($instanceNames, 'latest_instance_');
    $stmt = $db->prepare('SELECT MAX(last_update) AS latest_update
        FROM instances
        WHERE last_update IS NOT NULL
          AND name IN (' . implode(', ', $placeholders) . ')');
    bind_sqlite_text_values($stmt, $instanceNames, 'latest_instance_');
    $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    $value = $row['latest_update'] ?? null;

    return $value !== null && $value !== '' ? $value : null;
}

function is_refresh_needed(SQLite3 $db, array $config): bool
{
    $latestUpdate = get_latest_update($db, $config);
    if ($latestUpdate === null) {
        return true;
    }

    $settings = get_monitor_settings($config);
    $latestTimestamp = strtotime($latestUpdate);
    if ($latestTimestamp === false) {
        return true;
    }

    return (time() - $latestTimestamp) >= $settings['refresh_ttl_seconds'];
}

function normalize_history_hours(array $config, $hours): int
{
    $settings = get_monitor_settings($config);
    $value = (int)$hours;

    if (!in_array($value, $settings['history_hours_options'], true)) {
        return $settings['history_hours_default'];
    }

    return $value;
}

function get_refresh_lock_path(array $config): string
{
    $settings = get_monitor_settings($config);

    return $settings['lock_path'];
}

function is_sync_debug_enabled(array $config): bool
{
    $settings = get_monitor_settings($config);

    return (bool)$settings['debug_sync'];
}

function truncate_error_message(string $message, int $limit = 240): string
{
    $message = trim(preg_replace('/\s+/', ' ', $message));

    if (strlen($message) <= $limit) {
        return $message;
    }

    return substr($message, 0, $limit - 3) . '...';
}

function send_json_response($payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');

    $json = json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
        | JSON_INVALID_UTF8_SUBSTITUTE
    );

    if ($json === false) {
        http_response_code(500);
        echo '{"error":"Failed to encode JSON response"}';
        return;
    }

    echo $json;
}
