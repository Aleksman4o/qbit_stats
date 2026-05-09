<?php

$config = require __DIR__ . '/config.php';
require_once __DIR__ . '/collector.php';
require_once __DIR__ . '/data_functions.php';

$db = open_database($config);
$initialRefreshMeta = [
    'requested' => false,
    'needed' => is_refresh_needed($db, $config),
    'performed' => false,
    'in_progress' => false,
    'latest_update' => get_latest_update($db),
];
$currentData = get_current_data($db, $config, $initialRefreshMeta);
$historyData = get_history_data($db, $config, $currentData['meta']['history_hours_default']);
$db->close();

function json_for_script($value): string
{
    return json_encode(
        $value,
        JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
        | JSON_HEX_TAG
        | JSON_HEX_AMP
        | JSON_HEX_APOS
        | JSON_HEX_QUOT
    );
}

function format_speed($bytes): string
{
    $units = ['B/s', 'KB/s', 'MB/s', 'GB/s', 'TB/s'];
    $bytes = max((float)$bytes, 0);
    $power = $bytes > 0 ? min((int)floor(log($bytes, 1024)), count($units) - 1) : 0;

    return round($bytes / (1024 ** $power), 2) . ' ' . $units[$power];
}

function format_size($bytes): string
{
    $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
    $bytes = max((float)$bytes, 0);
    $power = $bytes > 0 ? min((int)floor(log($bytes, 1024)), count($units) - 1) : 0;

    return round($bytes / (1024 ** $power), 2) . ' ' . $units[$power];
}

function calculate_category_totals(array $categories): array
{
    return [
        'count' => count($categories),
        'up_speed' => array_sum(array_column($categories, 'up_speed')),
        'dl_speed' => array_sum(array_column($categories, 'dl_speed')),
        'active_torrents' => array_sum(array_column($categories, 'active_torrents')),
    ];
}

$categoryTotals = calculate_category_totals($currentData['categories']);
$historyOptions = $currentData['meta']['history_hours_options'];
$defaultHistoryHours = $historyData['hours'];
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>qBittorrent Category Monitor</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        :root {
            --bg: #eef2eb;
            --panel: #f9fbf7;
            --panel-strong: #ffffff;
            --border: #d7e0d1;
            --text: #1f2a1f;
            --muted: #607062;
            --accent: #1e7a47;
            --accent-soft: rgba(30, 122, 71, 0.12);
            --warning: #9a6b00;
            --warning-soft: rgba(154, 107, 0, 0.12);
            --danger: #b8453b;
            --danger-soft: rgba(184, 69, 59, 0.12);
            --shadow: 0 18px 40px rgba(34, 54, 35, 0.08);
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            padding: 28px;
            background:
                radial-gradient(circle at top left, rgba(30, 122, 71, 0.10), transparent 30%),
                linear-gradient(180deg, #f4f7f0 0%, var(--bg) 100%);
            color: var(--text);
            font-family: "IBM Plex Sans", "Segoe UI", sans-serif;
        }

        .page {
            max-width: 1480px;
            margin: 0 auto;
        }

        .page-header {
            display: flex;
            justify-content: space-between;
            gap: 24px;
            align-items: flex-start;
            margin-bottom: 20px;
        }

        .eyebrow {
            margin: 0 0 8px;
            font-size: 0.78rem;
            letter-spacing: 0.12em;
            text-transform: uppercase;
            color: var(--accent);
            font-weight: 700;
        }

        h1 {
            margin: 0;
            font-size: clamp(2rem, 3vw, 3rem);
            line-height: 1;
        }

        .subtitle {
            margin: 12px 0 0;
            max-width: 780px;
            color: var(--muted);
            font-size: 1rem;
        }

        .header-meta {
            display: flex;
            flex-direction: column;
            gap: 10px;
            align-items: flex-end;
        }

        .panel {
            background: linear-gradient(180deg, var(--panel-strong) 0%, var(--panel) 100%);
            border: 1px solid var(--border);
            border-radius: 20px;
            box-shadow: var(--shadow);
        }

        .toolbar {
            display: flex;
            flex-wrap: wrap;
            justify-content: space-between;
            gap: 18px;
            padding: 16px 18px;
            margin-bottom: 18px;
        }

        .toolbar-group {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
        }

        .toolbar label {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            color: var(--muted);
            font-size: 0.94rem;
        }

        .toolbar select,
        .toolbar button {
            border-radius: 12px;
            border: 1px solid var(--border);
            font: inherit;
        }

        .toolbar select {
            min-width: 90px;
            padding: 10px 12px;
            background: #fff;
            color: var(--text);
        }

        .toolbar button {
            padding: 10px 16px;
            background: var(--accent);
            color: #fff;
            cursor: pointer;
            transition: transform 0.15s ease, box-shadow 0.15s ease, opacity 0.15s ease;
            box-shadow: 0 10px 18px rgba(30, 122, 71, 0.18);
        }

        .toolbar button.secondary {
            background: transparent;
            color: var(--text);
            box-shadow: none;
        }

        .toolbar button:hover:not(:disabled) {
            transform: translateY(-1px);
        }

        .toolbar button:disabled {
            opacity: 0.6;
            cursor: progress;
        }

        .badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 12px;
            border-radius: 999px;
            font-size: 0.88rem;
            font-weight: 700;
            border: 1px solid transparent;
        }

        .badge.success {
            color: var(--accent);
            background: var(--accent-soft);
            border-color: rgba(30, 122, 71, 0.18);
        }

        .badge.warning {
            color: var(--warning);
            background: var(--warning-soft);
            border-color: rgba(154, 107, 0, 0.18);
        }

        .badge.error {
            color: var(--danger);
            background: var(--danger-soft);
            border-color: rgba(184, 69, 59, 0.18);
        }

        .badge.working {
            color: #1f5b8b;
            background: rgba(31, 91, 139, 0.12);
            border-color: rgba(31, 91, 139, 0.18);
        }

        .status-line {
            color: var(--muted);
            font-size: 0.92rem;
        }

        .summary-grid {
            display: grid;
            grid-template-columns: repeat(6, minmax(0, 1fr));
            gap: 14px;
            margin-bottom: 18px;
        }

        .summary-card {
            padding: 16px 18px;
        }

        .summary-card .label {
            display: block;
            margin-bottom: 12px;
            color: var(--muted);
            font-size: 0.82rem;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            font-weight: 700;
        }

        .summary-card .value {
            font-size: 1.55rem;
            font-weight: 700;
            line-height: 1.1;
        }

        .summary-card .subvalue {
            margin-top: 8px;
            color: var(--muted);
            font-size: 0.9rem;
        }

        .content-grid {
            display: grid;
            grid-template-columns: minmax(0, 1.8fr) minmax(360px, 0.95fr);
            gap: 18px;
            margin-bottom: 18px;
        }

        .panel-head {
            display: flex;
            justify-content: space-between;
            gap: 16px;
            align-items: flex-start;
            margin-bottom: 14px;
        }

        .panel-body {
            padding: 18px;
        }

        h2 {
            margin: 0;
            font-size: 1.2rem;
        }

        .panel-copy {
            margin: 6px 0 0;
            color: var(--muted);
            font-size: 0.92rem;
        }

        .chart-wrap {
            height: 420px;
        }

        .chart-wrap.compact {
            height: 420px;
        }

        .table-shell {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th,
        td {
            padding: 12px 14px;
            text-align: left;
            border-bottom: 1px solid rgba(215, 224, 209, 0.95);
            white-space: nowrap;
        }

        th {
            color: var(--muted);
            font-size: 0.84rem;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            font-weight: 700;
        }

        th.sortable {
            cursor: pointer;
            position: relative;
            padding-right: 26px;
            user-select: none;
        }

        th.sortable:hover {
            color: var(--text);
        }

        .sort-arrow {
            position: absolute;
            right: 10px;
            top: 50%;
            transform: translateY(-50%);
            width: 0;
            height: 0;
            border-left: 5px solid transparent;
            border-right: 5px solid transparent;
            border-bottom: 6px solid #b7c2b2;
            opacity: 0;
            transition: opacity 0.15s ease;
        }

        .sort-arrow.asc,
        .sort-arrow.desc {
            opacity: 1;
        }

        .sort-arrow.desc {
            border-bottom: none;
            border-top: 6px solid #7d8c7d;
        }

        tbody tr:hover {
            background: rgba(30, 122, 71, 0.04);
        }

        .muted {
            color: var(--muted);
        }

        .status-pill {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 5px 10px;
            border-radius: 999px;
            font-size: 0.84rem;
            font-weight: 700;
        }

        .status-pill.ok {
            color: var(--accent);
            background: var(--accent-soft);
        }

        .status-pill.error {
            color: var(--danger);
            background: var(--danger-soft);
        }

        .status-pill.unknown {
            color: var(--warning);
            background: var(--warning-soft);
        }

        .status-message {
            position: fixed;
            right: 28px;
            bottom: 28px;
            min-width: 280px;
            max-width: 420px;
            padding: 14px 16px;
            border-radius: 16px;
            border: 1px solid var(--border);
            background: rgba(255, 255, 255, 0.95);
            box-shadow: var(--shadow);
            color: var(--text);
            opacity: 0;
            pointer-events: none;
            transform: translateY(12px);
            transition: opacity 0.18s ease, transform 0.18s ease;
        }

        .status-message.visible {
            opacity: 1;
            transform: translateY(0);
        }

        .status-message.success {
            border-color: rgba(30, 122, 71, 0.18);
        }

        .status-message.error {
            border-color: rgba(184, 69, 59, 0.18);
        }

        @media (max-width: 1240px) {
            .summary-grid {
                grid-template-columns: repeat(3, minmax(0, 1fr));
            }

            .content-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 840px) {
            body {
                padding: 18px;
            }

            .page-header {
                flex-direction: column;
            }

            .header-meta {
                align-items: flex-start;
            }

            .summary-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .panel-body {
                padding: 16px;
            }

            .chart-wrap,
            .chart-wrap.compact {
                height: 320px;
            }
        }

        @media (max-width: 560px) {
            .summary-grid {
                grid-template-columns: 1fr;
            }

            .toolbar {
                padding: 14px;
            }

            th,
            td {
                padding: 10px 12px;
            }
        }
    </style>
</head>
<body>
    <div class="page">
        <header class="page-header">
            <div>
                <p class="eyebrow">qBit Stats V2</p>
                <h1>Категории в реальном времени</h1>
                <p class="subtitle">Дашборд сам собирает свежий срез, пока открыт. Наведите мышь на таймлайн, для мгновенного просмотра состава категорий в конкретный момент.</p>
            </div>
            <div class="header-meta">
                <span id="refreshBadge" class="badge success">Срез актуален</span>
                <span id="lastUpdateText" class="status-line">Последний срез: <?= htmlspecialchars($currentData['last_update'] ?? 'нет данных', ENT_QUOTES) ?></span>
            </div>
        </header>

        <section class="toolbar panel">
            <div class="toolbar-group">
                <button id="refreshButton" type="button">Обновить сейчас</button>
                <label>
                    <input id="autoRefreshCheckbox" type="checkbox" checked>
                    Поддерживать дашборд свежим
                </label>
                <label>
                    История
                    <select id="historyHoursSelect">
                        <?php foreach ($historyOptions as $option): ?>
                            <option value="<?= $option ?>"<?= $option === $defaultHistoryHours ? ' selected' : '' ?>><?= $option ?> ч</option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </div>
            <div class="toolbar-group">
                <span id="selectionStatus" class="badge warning">Просмотр: сейчас</span>
                <button id="clearSelectionButton" type="button" class="secondary" hidden>Вернуться к текущему</button>
            </div>
        </section>

        <section class="summary-grid">
            <article class="summary-card panel">
                <span class="label">Режим просмотра</span>
                <div id="selectionModeValue" class="value">Сейчас</div>
                <div id="selectionModeSubvalue" class="subvalue">Последний успешный срез</div>
            </article>
            <article class="summary-card panel">
                <span class="label">Категории</span>
                <div id="categoriesCountValue" class="value"><?= $categoryTotals['count'] ?></div>
                <div class="subvalue">В выбранном срезе</div>
            </article>
            <article class="summary-card panel">
                <span class="label">Upload</span>
                <div id="uploadTotalValue" class="value"><?= format_speed($categoryTotals['up_speed']) ?></div>
                <div class="subvalue">Суммарно по категориям</div>
            </article>
            <article class="summary-card panel">
                <span class="label">Download</span>
                <div id="downloadTotalValue" class="value"><?= format_speed($categoryTotals['dl_speed']) ?></div>
                <div class="subvalue">Суммарно по категориям</div>
            </article>
            <article class="summary-card panel">
                <span class="label">Активные торренты</span>
                <div id="activeTorrentsValue" class="value"><?= $categoryTotals['active_torrents'] ?></div>
                <div class="subvalue">По выбранному срезу</div>
            </article>
            <article class="summary-card panel">
                <span class="label">Инстансы</span>
                <div id="instancesHealthValue" class="value"><?= (int)$currentData['meta']['ok_count'] ?>/<?= (int)$currentData['meta']['instance_count'] ?></div>
                <div id="instancesHealthSubvalue" class="subvalue">ошибок: <?= (int)$currentData['meta']['error_count'] ?></div>
            </article>
        </section>

        <section class="content-grid">
            <article class="panel">
                <div class="panel-body">
                    <div class="panel-head">
                        <div>
                            <h2>Таймлайн скоростей по инстансам</h2>
                            <p class="panel-copy">Наведи курсор, чтобы моментально подменить таблицу категорий. Клик фиксирует момент до ручного сброса.</p>
                        </div>
                    </div>
                    <div class="chart-wrap">
                        <canvas id="historyChart"></canvas>
                    </div>
                </div>
            </article>

            <article class="panel">
                <div class="panel-body">
                    <div class="panel-head">
                        <div>
                            <h2>Текущий топ категорий</h2>
                            <p class="panel-copy">Барчарт всегда показывает тот же срез, что и таблица ниже.</p>
                        </div>
                    </div>
                    <div class="chart-wrap compact">
                        <canvas id="categoryChart"></canvas>
                    </div>
                </div>
            </article>
        </section>

        <section class="panel" style="margin-bottom: 18px;">
            <div class="panel-body">
                <div class="panel-head">
                    <div>
                        <h2 id="categoriesTitle">Категории: сейчас</h2>
                        <p id="categoriesCaption" class="panel-copy">Наведение не меняет текущий срез навсегда: клик фиксирует, уход курсора возвращает live-вид.</p>
                    </div>
                </div>
                <div class="table-shell">
                    <table id="categoriesTable">
                        <thead>
                            <tr>
                                <th class="sortable" data-column="category">Категория <span class="sort-arrow"></span></th>
                                <th class="sortable" data-column="up_speed">Upload <span class="sort-arrow"></span></th>
                                <th class="sortable" data-column="dl_speed">Download <span class="sort-arrow"></span></th>
                                <th class="sortable" data-column="active_torrents">Активные <span class="sort-arrow"></span></th>
                                <th class="sortable" data-column="total_size">Размер <span class="sort-arrow"></span></th>
                                <th class="sortable" data-column="uploaded_session">За сеанс <span class="sort-arrow"></span></th>
                                <th class="sortable" data-column="uploaded_total">Всего <span class="sort-arrow"></span></th>
                                <th class="sortable" data-column="instances">Инстансы <span class="sort-arrow"></span></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($currentData['categories'] as $category): ?>
                                <tr>
                                    <td><?= htmlspecialchars($category['category'], ENT_QUOTES) ?></td>
                                    <td><?= format_speed((int)$category['up_speed']) ?></td>
                                    <td><?= format_speed((int)$category['dl_speed']) ?></td>
                                    <td><?= (int)$category['active_torrents'] ?></td>
                                    <td><?= format_size((int)$category['total_size']) ?></td>
                                    <td><?= format_size((int)$category['uploaded_session']) ?></td>
                                    <td><?= format_size((int)$category['uploaded_total']) ?></td>
                                    <td class="muted"><?= htmlspecialchars($category['instances'] ?? '', ENT_QUOTES) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

        <section class="panel">
            <div class="panel-body">
                <div class="panel-head">
                    <div>
                        <h2>Состояние инстансов</h2>
                        <p class="panel-copy">Этот блок диагностический: здесь видно, кто дал срез, а кто выпал.</p>
                    </div>
                </div>
                <div class="table-shell">
                    <table id="instancesTable">
                        <thead>
                            <tr>
                                <th>Инстанс</th>
                                <th>Статус</th>
                                <th>Upload</th>
                                <th>Download</th>
                                <th>Последний успех</th>
                                <th>Последняя ошибка</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($currentData['instances'] as $instance): ?>
                                <tr>
                                    <td><?= htmlspecialchars($instance['name'], ENT_QUOTES) ?></td>
                                    <td>
                                        <span class="status-pill <?= htmlspecialchars($instance['status'] ?? 'unknown', ENT_QUOTES) ?>">
                                            <?= htmlspecialchars($instance['status'] ?? 'unknown', ENT_QUOTES) ?>
                                        </span>
                                    </td>
                                    <td><?= format_speed((int)($instance['up_speed'] ?? 0)) ?></td>
                                    <td><?= format_speed((int)($instance['dl_speed'] ?? 0)) ?></td>
                                    <td class="muted"><?= htmlspecialchars($instance['last_success'] ?? 'нет данных', ENT_QUOTES) ?></td>
                                    <td class="muted"><?= htmlspecialchars($instance['last_error'] ?? '—', ENT_QUOTES) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
    </div>

    <div id="statusMessage" class="status-message"></div>

    <script>
        const currentDataSeed = <?= json_for_script($currentData) ?>;
        const historyPayloadSeed = <?= json_for_script($historyData) ?>;
        const instanceNames = <?= json_for_script(array_column($config['instances'], 'name')) ?>;

        let currentData = currentDataSeed;
        let historyPayload = historyPayloadSeed;
        let selectedHistoryHours = historyPayload.hours || currentData.meta.history_hours_default;
        let historyChartInstance = null;
        let categoryChartInstance = null;
        let autoRefreshTimer = null;
        let followupRefreshTimer = null;
        let isRefreshing = false;
        let bootRefreshScheduled = false;
        let hoveredTimestamp = null;
        let pinnedTimestamp = null;
        let previewAbortController = null;
        let categorySortState = loadCategorySortState();

        const categoryCache = new Map();
        if (currentData.last_update) {
            categoryCache.set(currentData.last_update, currentData.categories);
        }

        const pollIntervalMs = Math.max(5000, (currentData.meta.dashboard_poll_interval_seconds || 30) * 1000);

        function formatSpeed(bytes) {
            const units = ['B/s', 'KB/s', 'MB/s', 'GB/s', 'TB/s'];
            const value = Math.max(Number(bytes) || 0, 0);
            if (value === 0) {
                return '0 B/s';
            }

            const power = Math.min(Math.floor(Math.log(value) / Math.log(1024)), units.length - 1);
            return `${(value / Math.pow(1024, power)).toFixed(2)} ${units[power]}`;
        }

        function formatSize(bytes) {
            const units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
            const value = Math.max(Number(bytes) || 0, 0);
            if (value === 0) {
                return '0 B';
            }

            const power = Math.min(Math.floor(Math.log(value) / Math.log(1024)), units.length - 1);
            return `${(value / Math.pow(1024, power)).toFixed(2)} ${units[power]}`;
        }

        function escapeHtml(value) {
            return String(value ?? '')
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        function formatTimestamp(timestamp) {
            if (!timestamp) {
                return 'нет данных';
            }

            const date = new Date(timestamp.replace(' ', 'T'));
            if (Number.isNaN(date.getTime())) {
                return timestamp;
            }

            return date.toLocaleString('ru-RU', {
                year: 'numeric',
                month: '2-digit',
                day: '2-digit',
                hour: '2-digit',
                minute: '2-digit',
                second: '2-digit',
            });
        }

        function formatTimeLabel(timestamp) {
            if (!timestamp) {
                return '';
            }

            const date = new Date(timestamp.replace(' ', 'T'));
            if (Number.isNaN(date.getTime())) {
                return timestamp;
            }

            return date.toLocaleTimeString('ru-RU', {
                hour: '2-digit',
                minute: '2-digit',
                second: '2-digit',
            });
        }

        function getStatusClass(status) {
            if (status === 'ok') {
                return 'ok';
            }

            if (status === 'error') {
                return 'error';
            }

            return 'unknown';
        }

        function makeColor(seed, alpha) {
            let hash = 0;
            for (const char of seed) {
                hash = ((hash << 5) - hash) + char.charCodeAt(0);
                hash |= 0;
            }

            const red = Math.abs((hash * 53) % 200) + 20;
            const green = Math.abs((hash * 97) % 180) + 30;
            const blue = Math.abs((hash * 131) % 160) + 40;

            return `rgba(${red}, ${green}, ${blue}, ${alpha})`;
        }

        function computeCategoryTotals(categories) {
            return categories.reduce((totals, category) => {
                totals.count += 1;
                totals.upSpeed += Number(category.up_speed) || 0;
                totals.dlSpeed += Number(category.dl_speed) || 0;
                totals.activeTorrents += Number(category.active_torrents) || 0;
                return totals;
            }, { count: 0, upSpeed: 0, dlSpeed: 0, activeTorrents: 0 });
        }

        function getDefaultCategorySortState() {
            return {
                column: 'up_speed',
                direction: 'desc',
            };
        }

        function loadCategorySortState() {
            const fallback = getDefaultCategorySortState();
            const raw = localStorage.getItem('qbitStatsCategorySort');
            if (!raw) {
                return fallback;
            }

            try {
                const parsed = JSON.parse(raw);
                if (!parsed || typeof parsed.column !== 'string' || !['asc', 'desc'].includes(parsed.direction)) {
                    return fallback;
                }

                return parsed;
            } catch (error) {
                return fallback;
            }
        }

        function saveCategorySortState() {
            localStorage.setItem('qbitStatsCategorySort', JSON.stringify(categorySortState));
        }

        function sortCategories(categories) {
            const sorted = [...categories];
            const { column, direction } = categorySortState;

            sorted.sort((left, right) => {
                let result = 0;

                if (column === 'category' || column === 'instances') {
                    result = String(left[column] || '').localeCompare(String(right[column] || ''), 'ru');
                } else {
                    result = (Number(left[column]) || 0) - (Number(right[column]) || 0);
                }

                if (result === 0 && column !== 'category') {
                    result = String(left.category || '').localeCompare(String(right.category || ''), 'ru');
                }

                return direction === 'asc' ? result : -result;
            });

            return sorted;
        }

        function updateCategorySortIndicators() {
            document.querySelectorAll('#categoriesTable thead th.sortable').forEach(header => {
                const arrow = header.querySelector('.sort-arrow');
                if (!arrow) {
                    return;
                }

                arrow.classList.remove('asc', 'desc');
                if (header.dataset.column === categorySortState.column) {
                    arrow.classList.add(categorySortState.direction);
                }
            });
        }

        function buildHistoryModel(items) {
            const timestamps = [...new Set(items.map(item => item.timestamp))].sort();
            const labels = timestamps.map(formatTimeLabel);

            const downloadDatasets = instanceNames.map(instanceName => ({
                label: `${instanceName} ↓ Download`,
                data: timestamps.map(timestamp => {
                    const row = items.find(item => item.instance_name === instanceName && item.timestamp === timestamp);
                    return row ? Number(row.dl_speed) || 0 : 0;
                }),
                backgroundColor: makeColor(`${instanceName}-dl`, 0.16),
                borderColor: makeColor(`${instanceName}-dl`, 0.78),
                borderWidth: 1.4,
                fill: true,
                tension: 0.24,
                stack: 'download',
                pointRadius: 0,
                pointHoverRadius: 4,
            }));

            const uploadDatasets = instanceNames.map(instanceName => ({
                label: `${instanceName} ↑ Upload`,
                data: timestamps.map(timestamp => {
                    const row = items.find(item => item.instance_name === instanceName && item.timestamp === timestamp);
                    return row ? Number(row.up_speed) || 0 : 0;
                }),
                backgroundColor: makeColor(`${instanceName}-up`, 0.08),
                borderColor: makeColor(`${instanceName}-up`, 0.48),
                borderWidth: 1.1,
                fill: true,
                tension: 0.24,
                stack: 'upload',
                pointRadius: 0,
                pointHoverRadius: 4,
                borderDash: [5, 4],
            }));

            return {
                timestamps,
                labels,
                datasets: [...downloadDatasets, ...uploadDatasets],
            };
        }

        function renderHistoryChart() {
            const model = buildHistoryModel(historyPayload.data || []);
            const context = document.getElementById('historyChart').getContext('2d');

            if (!historyChartInstance) {
                historyChartInstance = new Chart(context, {
                    type: 'line',
                    data: {
                        labels: model.labels,
                        datasets: model.datasets,
                    },
                    options: {
                        maintainAspectRatio: false,
                        responsive: true,
                        interaction: {
                            intersect: false,
                            mode: 'index',
                        },
                        scales: {
                            x: {
                                stacked: true,
                                grid: {
                                    display: false,
                                },
                            },
                            y: {
                                stacked: true,
                                beginAtZero: true,
                                ticks: {
                                    callback: value => formatSpeed(value),
                                },
                            },
                        },
                        plugins: {
                            legend: {
                                position: 'top',
                                labels: {
                                    boxWidth: 10,
                                    usePointStyle: true,
                                },
                            },
                            tooltip: {
                                mode: 'index',
                                intersect: false,
                                callbacks: {
                                    title: items => {
                                        const timestamp = historyChartInstance?.$timestamps?.[items[0]?.dataIndex ?? -1];
                                        return formatTimestamp(timestamp);
                                    },
                                    label: context => `${context.dataset.label}: ${formatSpeed(context.raw)}`,
                                },
                            },
                        },
                    },
                });
            } else {
                historyChartInstance.data.labels = model.labels;
                historyChartInstance.data.datasets = model.datasets;
                historyChartInstance.update();
            }

            historyChartInstance.$timestamps = model.timestamps;
        }

        function renderCategoryChart(categories, options = {}) {
            const animate = options.animate === true;
            const context = document.getElementById('categoryChart').getContext('2d');
            const topCategories = [...categories]
                .sort((left, right) => (Number(right.up_speed) || 0) - (Number(left.up_speed) || 0))
                .slice(0, 12);

            const chartData = {
                labels: topCategories.map(category => category.category),
                datasets: [{
                    label: 'Upload',
                    data: topCategories.map(category => Number(category.up_speed) || 0),
                    backgroundColor: topCategories.map(category => makeColor(category.category, 0.28)),
                    borderColor: topCategories.map(category => makeColor(category.category, 0.92)),
                    borderWidth: 1.4,
                    borderRadius: 10,
                    categoryPercentage: 0.96,
                    barPercentage: 0.98,
                    maxBarThickness: 22,
                }],
            };

            if (!categoryChartInstance) {
                categoryChartInstance = new Chart(context, {
                    type: 'bar',
                    data: chartData,
                    options: {
                        indexAxis: 'y',
                        maintainAspectRatio: false,
                        responsive: true,
                        scales: {
                            x: {
                                beginAtZero: true,
                                ticks: {
                                    callback: value => formatSpeed(value),
                                },
                            },
                            y: {
                                grid: {
                                    display: false,
                                },
                            },
                        },
                        plugins: {
                            legend: {
                                display: false,
                            },
                            tooltip: {
                                callbacks: {
                                    label: context => formatSpeed(context.raw),
                                },
                            },
                        },
                    },
                });
            } else {
                categoryChartInstance.data = chartData;
                categoryChartInstance.update(animate ? undefined : 'none');
            }
        }

        function renderCategoriesTable(categories) {
            const tbody = document.querySelector('#categoriesTable tbody');
            const sortedCategories = sortCategories(categories);
            tbody.innerHTML = sortedCategories.map(category => `
                <tr>
                    <td>${escapeHtml(category.category)}</td>
                    <td>${formatSpeed(category.up_speed)}</td>
                    <td>${formatSpeed(category.dl_speed)}</td>
                    <td>${Number(category.active_torrents) || 0}</td>
                    <td>${formatSize(category.total_size)}</td>
                    <td>${formatSize(category.uploaded_session)}</td>
                    <td>${formatSize(category.uploaded_total)}</td>
                    <td class="muted">${escapeHtml(category.instances || '')}</td>
                </tr>
            `).join('');
            updateCategorySortIndicators();
        }

        function renderInstancesTable() {
            const tbody = document.querySelector('#instancesTable tbody');
            tbody.innerHTML = currentData.instances.map(instance => `
                <tr>
                    <td>${escapeHtml(instance.name)}</td>
                    <td><span class="status-pill ${getStatusClass(instance.status)}">${escapeHtml(instance.status || 'unknown')}</span></td>
                    <td>${formatSpeed(instance.up_speed)}</td>
                    <td>${formatSpeed(instance.dl_speed)}</td>
                    <td class="muted">${escapeHtml(instance.last_success || 'нет данных')}</td>
                    <td class="muted">${escapeHtml(instance.last_error || '—')}</td>
                </tr>
            `).join('');
        }

        function updateSelectionCards(mode, timestamp) {
            const titleMap = {
                live: 'Сейчас',
                hover: 'Просмотр',
                pinned: 'Фиксация',
            };

            document.getElementById('selectionModeValue').textContent = titleMap[mode] || 'Сейчас';
            document.getElementById('selectionModeSubvalue').textContent = mode === 'live'
                ? 'Текущий успешный срез'
                : formatTimestamp(timestamp);
        }

        function updateCategoryMetrics(categories) {
            const totals = computeCategoryTotals(categories);
            document.getElementById('categoriesCountValue').textContent = totals.count;
            document.getElementById('uploadTotalValue').textContent = formatSpeed(totals.upSpeed);
            document.getElementById('downloadTotalValue').textContent = formatSpeed(totals.dlSpeed);
            document.getElementById('activeTorrentsValue').textContent = totals.activeTorrents;
        }

        function updateInstancesHealth() {
            document.getElementById('instancesHealthValue').textContent = `${currentData.meta.ok_count}/${currentData.meta.instance_count}`;
            document.getElementById('instancesHealthSubvalue').textContent = `ошибок: ${currentData.meta.error_count}`;
        }

        function updateRefreshStatus() {
            const refreshMeta = currentData.meta.refresh || {};
            const badge = document.getElementById('refreshBadge');
            const lastUpdate = currentData.last_update ? formatTimestamp(currentData.last_update) : 'нет данных';

            let text = 'Срез актуален';
            let badgeClass = 'success';

            if (refreshMeta.in_progress) {
                text = 'Идёт обновление';
                badgeClass = 'working';
            } else if (refreshMeta.error) {
                text = 'Последний refresh с ошибкой';
                badgeClass = 'error';
            } else if (refreshMeta.summary?.status === 'partial' || currentData.meta.error_count > 0) {
                text = 'Часть инстансов не ответила';
                badgeClass = 'warning';
            } else if (currentData.meta.is_stale) {
                text = 'Срез устарел';
                badgeClass = 'warning';
            }

            badge.className = `badge ${badgeClass}`;
            badge.textContent = text;
            document.getElementById('lastUpdateText').textContent = `Последний срез: ${lastUpdate}`;
        }

        function setRefreshBadge(text, badgeClass) {
            const badge = document.getElementById('refreshBadge');
            badge.className = `badge ${badgeClass}`;
            badge.textContent = text;
        }

        function updateSelectionBadge(mode, timestamp) {
            const badge = document.getElementById('selectionStatus');
            const clearButton = document.getElementById('clearSelectionButton');

            if (mode === 'pinned') {
                badge.className = 'badge success';
                badge.textContent = `Просмотр: фиксированный срез ${formatTimestamp(timestamp)}`;
                clearButton.hidden = false;
            } else if (mode === 'hover') {
                badge.className = 'badge warning';
                badge.textContent = `Просмотр: предпросмотр ${formatTimestamp(timestamp)}`;
                clearButton.hidden = true;
            } else {
                badge.className = 'badge warning';
                badge.textContent = 'Просмотр: сейчас';
                clearButton.hidden = true;
            }
        }

        function applyCategoryView(categories, mode, timestamp = null) {
            renderCategoriesTable(categories);
            renderCategoryChart(categories);
            updateCategoryMetrics(categories);
            updateSelectionCards(mode, timestamp);
            updateSelectionBadge(mode, timestamp);

            const title = document.getElementById('categoriesTitle');
            const caption = document.getElementById('categoriesCaption');

            if (mode === 'live') {
                title.textContent = 'Категории: сейчас';
                caption.textContent = 'Наведение не меняет текущий срез навсегда: клик фиксирует, уход курсора возвращает live-вид.';
            } else if (mode === 'hover') {
                title.textContent = `Категории: ${formatTimestamp(timestamp)}`;
                caption.textContent = 'Это временный предпросмотр по наведению. Клик зафиксирует момент.';
            } else {
                title.textContent = `Категории: ${formatTimestamp(timestamp)}`;
                caption.textContent = 'Срез зафиксирован. Кнопка справа или повторный клик по точке вернёт текущий момент.';
            }
        }

        function applyLiveView() {
            applyCategoryView(currentData.categories, 'live');
        }

        async function loadCategorySnapshot(timestamp, mode) {
            if (!timestamp) {
                return;
            }

            if (timestamp === currentData.last_update && currentData.categories.length > 0) {
                categoryCache.set(timestamp, currentData.categories);
            }

            if (categoryCache.has(timestamp)) {
                applyCategoryView(categoryCache.get(timestamp), mode, timestamp);
                return;
            }

            if (previewAbortController) {
                previewAbortController.abort();
            }

            previewAbortController = new AbortController();

            try {
                const response = await fetch(`get_category_history.php?timestamp=${encodeURIComponent(timestamp)}`, {
                    cache: 'no-store',
                    signal: previewAbortController.signal,
                });
                const payload = await response.json();

                if (!response.ok || payload.error) {
                    throw new Error(payload.error || 'Не удалось загрузить исторический срез');
                }

                categoryCache.set(timestamp, payload.data || []);
                if ((mode === 'pinned' && pinnedTimestamp === timestamp) || (mode === 'hover' && !pinnedTimestamp && hoveredTimestamp === timestamp)) {
                    applyCategoryView(payload.data || [], mode, timestamp);
                }
            } catch (error) {
                if (error.name === 'AbortError') {
                    return;
                }

                showStatus(error.message || 'Ошибка загрузки исторического среза', 'error');
                if (!pinnedTimestamp) {
                    applyLiveView();
                }
            } finally {
                previewAbortController = null;
            }
        }

        function pruneCategoryCache() {
            const validTimestamps = new Set((historyPayload.data || []).map(item => item.timestamp));
            if (currentData.last_update) {
                validTimestamps.add(currentData.last_update);
            }

            for (const timestamp of categoryCache.keys()) {
                if (!validTimestamps.has(timestamp)) {
                    categoryCache.delete(timestamp);
                }
            }
        }

        async function refreshHistory(hours) {
            const response = await fetch(`get_history_data.php?hours=${encodeURIComponent(hours)}`, {
                cache: 'no-store',
            });
            const payload = await response.json();

            if (!response.ok) {
                throw new Error('Не удалось загрузить историю');
            }

            historyPayload = payload;
            selectedHistoryHours = payload.hours || hours;
            document.getElementById('historyHoursSelect').value = String(selectedHistoryHours);
            renderHistoryChart();
            pruneCategoryCache();

            if (pinnedTimestamp && !(historyPayload.data || []).some(item => item.timestamp === pinnedTimestamp)) {
                pinnedTimestamp = null;
                hoveredTimestamp = null;
                applyLiveView();
            }
        }

        async function refreshDashboard({ force = false } = {}) {
            if (isRefreshing) {
                return;
            }

            isRefreshing = true;
            document.getElementById('refreshButton').disabled = true;

            if (force) {
                setRefreshBadge('Обновляю срез', 'working');
            } else if (currentData.meta.is_stale || !currentData.last_update) {
                setRefreshBadge('Проверяю инстансы', 'working');
            }

            try {
                const query = force ? '?force=1' : '';
                const response = await fetch(`get_current_data.php${query}`, {
                    cache: 'no-store',
                });
                const payload = await response.json();

                if (!response.ok && !payload.last_update) {
                    throw new Error(payload.meta?.refresh?.error || 'Не удалось получить текущие данные');
                }

                currentData = payload;
                if (currentData.last_update) {
                    categoryCache.set(currentData.last_update, currentData.categories);
                }

                updateInstancesHealth();
                updateRefreshStatus();
                renderInstancesTable();

                await refreshHistory(selectedHistoryHours);

                if (pinnedTimestamp) {
                    await loadCategorySnapshot(pinnedTimestamp, 'pinned');
                } else {
                    applyLiveView();
                }

                if (force) {
                    const summary = currentData.meta.refresh?.summary;
                    if (summary) {
                        const suffix = summary.error_count > 0
                            ? `, ошибок: ${summary.error_count}`
                            : '';
                        showStatus(`Срез обновлён: успешно ${summary.success_count}${suffix}`, summary.error_count > 0 ? 'error' : 'success');
                    } else if (currentData.meta.refresh?.in_progress) {
                        showStatus('Обновление уже выполняется другим запросом', 'success');
                    }
                }

                const autoRefreshCheckbox = document.getElementById('autoRefreshCheckbox');
                if (!force && autoRefreshCheckbox?.checked && currentData.meta.refresh?.in_progress && !currentData.last_update) {
                    scheduleFollowupRefresh(3000);
                }
            } catch (error) {
                showStatus(error.message || 'Ошибка обновления', 'error');
            } finally {
                isRefreshing = false;
                document.getElementById('refreshButton').disabled = false;
            }
        }

        function scheduleFollowupRefresh(delayMs) {
            if (followupRefreshTimer) {
                clearTimeout(followupRefreshTimer);
            }

            followupRefreshTimer = window.setTimeout(() => {
                followupRefreshTimer = null;
                refreshDashboard({ force: false });
            }, delayMs);
        }

        function configureAutoRefresh(enabled) {
            if (autoRefreshTimer) {
                clearInterval(autoRefreshTimer);
                autoRefreshTimer = null;
            }

            if (followupRefreshTimer) {
                clearTimeout(followupRefreshTimer);
                followupRefreshTimer = null;
            }

            if (enabled) {
                autoRefreshTimer = setInterval(() => {
                    refreshDashboard({ force: false });
                }, pollIntervalMs);
            }
        }

        function showStatus(message, type = 'success') {
            const element = document.getElementById('statusMessage');
            element.className = `status-message visible ${type}`;
            element.textContent = message;

            window.clearTimeout(showStatus.timeoutId);
            showStatus.timeoutId = window.setTimeout(() => {
                element.className = 'status-message';
            }, 3200);
        }

        function resolveTimestampFromEvent(event) {
            if (!historyChartInstance || !historyChartInstance.$timestamps) {
                return null;
            }

            const points = historyChartInstance.getElementsAtEventForMode(event, 'nearest', { intersect: false }, false);
            if (!points.length) {
                return null;
            }

            return historyChartInstance.$timestamps[points[0].index] || null;
        }

        function attachHistoryInteractions() {
            const canvas = document.getElementById('historyChart');

            canvas.addEventListener('mousemove', event => {
                if (pinnedTimestamp) {
                    return;
                }

                const timestamp = resolveTimestampFromEvent(event);
                if (!timestamp || hoveredTimestamp === timestamp) {
                    return;
                }

                hoveredTimestamp = timestamp;
                loadCategorySnapshot(timestamp, 'hover');
            });

            canvas.addEventListener('mouseleave', () => {
                hoveredTimestamp = null;
                if (!pinnedTimestamp) {
                    applyLiveView();
                }
            });

            canvas.addEventListener('click', async event => {
                const timestamp = resolveTimestampFromEvent(event);
                if (!timestamp) {
                    return;
                }

                if (pinnedTimestamp === timestamp) {
                    pinnedTimestamp = null;
                    hoveredTimestamp = null;
                    applyLiveView();
                    return;
                }

                pinnedTimestamp = timestamp;
                hoveredTimestamp = timestamp;
                await loadCategorySnapshot(timestamp, 'pinned');
            });
        }

        function attachCategorySorting() {
            document.querySelectorAll('#categoriesTable thead th.sortable').forEach(header => {
                header.addEventListener('click', () => {
                    const column = header.dataset.column;
                    if (!column) {
                        return;
                    }

                    if (categorySortState.column === column) {
                        categorySortState.direction = categorySortState.direction === 'asc' ? 'desc' : 'asc';
                    } else {
                        categorySortState = {
                            column,
                            direction: column === 'category' || column === 'instances' ? 'asc' : 'desc',
                        };
                    }

                    saveCategorySortState();

                    if (pinnedTimestamp) {
                        loadCategorySnapshot(pinnedTimestamp, 'pinned');
                    } else if (hoveredTimestamp) {
                        loadCategorySnapshot(hoveredTimestamp, 'hover');
                    } else {
                        applyLiveView();
                    }
                });
            });
        }

        function boot() {
            renderHistoryChart();
            renderCategoryChart(currentData.categories);
            renderInstancesTable();
            updateInstancesHealth();
            updateRefreshStatus();
            applyLiveView();
            attachHistoryInteractions();
            attachCategorySorting();

            const refreshButton = document.getElementById('refreshButton');
            const autoRefreshCheckbox = document.getElementById('autoRefreshCheckbox');
            const historyHoursSelect = document.getElementById('historyHoursSelect');
            const clearSelectionButton = document.getElementById('clearSelectionButton');

            const storedAutoRefresh = localStorage.getItem('qbitStatsAutoRefresh');
            const autoRefreshEnabled = storedAutoRefresh === null ? true : storedAutoRefresh === 'true';
            autoRefreshCheckbox.checked = autoRefreshEnabled;
            configureAutoRefresh(autoRefreshEnabled);

            refreshButton.addEventListener('click', () => {
                refreshDashboard({ force: true });
            });

            autoRefreshCheckbox.addEventListener('change', event => {
                const enabled = event.target.checked;
                localStorage.setItem('qbitStatsAutoRefresh', String(enabled));
                configureAutoRefresh(enabled);
            });

            historyHoursSelect.addEventListener('change', async event => {
                try {
                    await refreshHistory(Number(event.target.value));
                    if (pinnedTimestamp) {
                        await loadCategorySnapshot(pinnedTimestamp, 'pinned');
                    } else {
                        applyLiveView();
                    }
                } catch (error) {
                    showStatus(error.message || 'Не удалось переключить диапазон истории', 'error');
                }
            });

            clearSelectionButton.addEventListener('click', () => {
                pinnedTimestamp = null;
                hoveredTimestamp = null;
                applyLiveView();
            });

            if (currentData.meta.refresh?.error) {
                showStatus(currentData.meta.refresh.error, 'error');
            }

            if (!bootRefreshScheduled) {
                bootRefreshScheduled = true;
                window.setTimeout(() => {
                    refreshDashboard({ force: false });
                }, 120);
            }
        }

        boot();
    </script>
</body>
</html>
