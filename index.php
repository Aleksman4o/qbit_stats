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
    'latest_update' => get_latest_update($db, $config),
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
    <script>
        (function () {
            const storageKey = 'qbitStatsTheme';
            let theme = 'light';

            try {
                const storedTheme = localStorage.getItem(storageKey);
                if (storedTheme === 'light' || storedTheme === 'dark') {
                    theme = storedTheme;
                } else if (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) {
                    theme = 'dark';
                }
            } catch (error) {
            }

            document.documentElement.dataset.theme = theme;
        }());
    </script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        :root {
            color-scheme: light;
            --bg: #eef2eb;
            --bg-top: #f4f7f0;
            --bg-accent-glow: rgba(30, 122, 71, 0.10);
            --panel: #f9fbf7;
            --panel-strong: #ffffff;
            --border: #d7e0d1;
            --text: #1f2a1f;
            --muted: #607062;
            --accent: #1e7a47;
            --accent-soft: rgba(30, 122, 71, 0.12);
            --accent-border: rgba(30, 122, 71, 0.18);
            --warning: #9a6b00;
            --warning-soft: rgba(154, 107, 0, 0.12);
            --warning-border: rgba(154, 107, 0, 0.18);
            --danger: #b8453b;
            --danger-soft: rgba(184, 69, 59, 0.12);
            --danger-border: rgba(184, 69, 59, 0.18);
            --info: #1f5b8b;
            --info-soft: rgba(31, 91, 139, 0.12);
            --info-border: rgba(31, 91, 139, 0.18);
            --input-bg: #ffffff;
            --input-text: #1f2a1f;
            --button-shadow: 0 10px 18px rgba(30, 122, 71, 0.18);
            --table-border: rgba(215, 224, 209, 0.95);
            --row-hover: rgba(30, 122, 71, 0.04);
            --sort-idle: #b7c2b2;
            --sort-active: #7d8c7d;
            --overlay-panel: rgba(255, 255, 255, 0.95);
            --chart-grid: rgba(134, 151, 136, 0.22);
            --chart-text: #607062;
            --chart-tooltip-bg: rgba(23, 32, 24, 0.94);
            --chart-tooltip-text: #f6faf5;
            --chart-tooltip-border: rgba(110, 132, 112, 0.26);
            --shadow: 0 18px 40px rgba(34, 54, 35, 0.08);
        }

        :root[data-theme="dark"] {
            color-scheme: dark;
            --bg: #070b09;
            --bg-top: #0d1310;
            --bg-accent-glow: rgba(90, 193, 126, 0.10);
            --panel: #0f1512;
            --panel-strong: #141c17;
            --border: #202c24;
            --text: #edf3eb;
            --muted: #94a497;
            --accent: #63cc88;
            --accent-soft: rgba(99, 204, 136, 0.16);
            --accent-border: rgba(99, 204, 136, 0.24);
            --warning: #e2b65d;
            --warning-soft: rgba(226, 182, 93, 0.14);
            --warning-border: rgba(226, 182, 93, 0.24);
            --danger: #ef867a;
            --danger-soft: rgba(239, 134, 122, 0.16);
            --danger-border: rgba(239, 134, 122, 0.24);
            --info: #7dc0f4;
            --info-soft: rgba(125, 192, 244, 0.16);
            --info-border: rgba(125, 192, 244, 0.24);
            --input-bg: #0b100d;
            --input-text: #edf3eb;
            --button-shadow: 0 14px 24px rgba(0, 0, 0, 0.38);
            --table-border: rgba(42, 55, 46, 0.92);
            --row-hover: rgba(99, 204, 136, 0.07);
            --sort-idle: #536458;
            --sort-active: #d7e2d9;
            --overlay-panel: rgba(14, 19, 16, 0.97);
            --chart-grid: rgba(104, 123, 110, 0.18);
            --chart-text: #adbcaf;
            --chart-tooltip-bg: rgba(12, 17, 14, 0.98);
            --chart-tooltip-text: #edf3eb;
            --chart-tooltip-border: rgba(87, 107, 93, 0.34);
            --shadow: 0 24px 56px rgba(0, 0, 0, 0.42);
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            padding: 28px;
            background:
                radial-gradient(circle at top left, var(--bg-accent-glow), transparent 30%),
                linear-gradient(180deg, var(--bg-top) 0%, var(--bg) 100%);
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

        .theme-toggle {
            padding: 9px 14px;
            border: 1px solid var(--border);
            border-radius: 999px;
            background: var(--panel-strong);
            color: var(--text);
            font: inherit;
            font-weight: 700;
            cursor: pointer;
            transition: transform 0.15s ease, border-color 0.15s ease, background 0.15s ease;
        }

        .theme-toggle:hover {
            transform: translateY(-1px);
            border-color: var(--accent-border);
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

        .toolbar input[type="checkbox"] {
            accent-color: var(--accent);
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
            background: var(--input-bg);
            color: var(--input-text);
        }

        .toolbar button {
            padding: 10px 16px;
            background: var(--accent);
            color: #fff;
            cursor: pointer;
            transition: transform 0.15s ease, box-shadow 0.15s ease, opacity 0.15s ease;
            box-shadow: var(--button-shadow);
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
            border-color: var(--accent-border);
        }

        .badge.warning {
            color: var(--warning);
            background: var(--warning-soft);
            border-color: var(--warning-border);
        }

        .badge.error {
            color: var(--danger);
            background: var(--danger-soft);
            border-color: var(--danger-border);
        }

        .badge.working {
            color: var(--info);
            background: var(--info-soft);
            border-color: var(--info-border);
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

        .full-width-panel {
            margin-bottom: 18px;
        }

        .category-history-legend {
            display: flex;
            flex-wrap: wrap;
            gap: 7px 10px;
            max-height: 108px;
            margin-bottom: 10px;
            padding: 4px 2px 8px;
            overflow-y: auto;
        }

        .category-history-legend[hidden] {
            display: none;
        }

        .category-history-legend button {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 3px 6px;
            border: 0;
            border-radius: 8px;
            background: transparent;
            color: var(--muted);
            font: inherit;
            font-size: 0.75rem;
            cursor: pointer;
        }

        .category-history-legend button:hover {
            color: var(--text);
            background: var(--row-hover);
        }

        .category-history-legend button.is-hidden {
            opacity: 0.38;
        }

        .category-history-legend .series-swatch {
            width: 10px;
            height: 10px;
            flex: 0 0 10px;
            border: 2px solid var(--series-color);
            border-radius: 50%;
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

        .panel-action {
            flex: 0 0 auto;
            padding: 8px 12px;
            border: 1px solid var(--border);
            border-radius: 10px;
            background: var(--panel-strong);
            color: var(--muted);
            font: inherit;
            font-size: 0.82rem;
            font-weight: 700;
            cursor: pointer;
        }

        .panel-action:hover {
            color: var(--text);
            border-color: var(--accent-border);
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
            border-bottom: 1px solid var(--table-border);
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
            border-bottom: 6px solid var(--sort-idle);
            opacity: 0;
            transition: opacity 0.15s ease;
        }

        .sort-arrow.asc,
        .sort-arrow.desc {
            opacity: 1;
        }

        .sort-arrow.desc {
            border-bottom: none;
            border-top: 6px solid var(--sort-active);
        }

        tbody tr:hover {
            background: var(--row-hover);
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
            background: var(--overlay-panel);
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
            border-color: var(--accent-border);
        }

        .status-message.error {
            border-color: var(--danger-border);
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
                <button id="themeToggleButton" type="button" class="theme-toggle">Тема</button>
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

        <section class="panel full-width-panel">
            <div class="panel-body">
                <div class="panel-head">
                    <div>
                        <h2>История скорости отдачи по категориям</h2>
                        <p class="panel-copy">Все категории за выбранный период. Двигайте курсор по вертикали, чтобы листать окно скоростей; клик фиксирует исторический срез.</p>
                    </div>
                    <button
                        id="categoryHistoryLegendToggle"
                        type="button"
                        class="panel-action"
                        aria-controls="categoryHistoryLegend"
                        aria-expanded="true"
                    >Скрыть легенду</button>
                </div>
                <div id="categoryHistoryLegend" class="category-history-legend" aria-label="Категории графика"></div>
                <div class="chart-wrap">
                    <canvas id="categoryHistoryChart"></canvas>
                </div>
            </div>
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
        let categoryHistoryChartInstance = null;
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
        const themeStorageKey = 'qbitStatsTheme';
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

        function getCurrentTheme() {
            return document.documentElement.dataset.theme === 'dark' ? 'dark' : 'light';
        }

        function readThemeVar(name) {
            return getComputedStyle(document.documentElement).getPropertyValue(name).trim();
        }

        function updateThemeToggle(theme) {
            const button = document.getElementById('themeToggleButton');
            if (!button) {
                return;
            }

            const isDark = theme === 'dark';
            button.textContent = `Тема: ${isDark ? 'тёмная' : 'светлая'}`;
            button.title = isDark ? 'Переключить на светлую тему' : 'Переключить на тёмную тему';
            button.setAttribute('aria-pressed', isDark ? 'true' : 'false');
        }

        function applyThemeToCharts() {
            const chartText = readThemeVar('--chart-text');
            const chartGrid = readThemeVar('--chart-grid');
            const tooltipBackground = readThemeVar('--chart-tooltip-bg');
            const tooltipText = readThemeVar('--chart-tooltip-text');
            const tooltipBorder = readThemeVar('--chart-tooltip-border');

            if (historyChartInstance) {
                historyChartInstance.options.scales.x.ticks = {
                    ...(historyChartInstance.options.scales.x.ticks || {}),
                    color: chartText,
                };
                historyChartInstance.options.scales.y.ticks = {
                    ...(historyChartInstance.options.scales.y.ticks || {}),
                    color: chartText,
                };
                historyChartInstance.options.scales.y.grid = {
                    ...(historyChartInstance.options.scales.y.grid || {}),
                    color: chartGrid,
                };
                historyChartInstance.options.plugins.legend.labels = {
                    ...(historyChartInstance.options.plugins.legend.labels || {}),
                    color: chartText,
                };
                historyChartInstance.options.plugins.tooltip = {
                    ...(historyChartInstance.options.plugins.tooltip || {}),
                    backgroundColor: tooltipBackground,
                    titleColor: tooltipText,
                    bodyColor: tooltipText,
                    borderColor: tooltipBorder,
                    borderWidth: 1,
                };
                historyChartInstance.update('none');
            }

            if (categoryHistoryChartInstance) {
                categoryHistoryChartInstance.options.scales.x.ticks = {
                    ...(categoryHistoryChartInstance.options.scales.x.ticks || {}),
                    color: chartText,
                };
                categoryHistoryChartInstance.options.scales.y.ticks = {
                    ...(categoryHistoryChartInstance.options.scales.y.ticks || {}),
                    color: chartText,
                };
                categoryHistoryChartInstance.options.scales.y.grid = {
                    ...(categoryHistoryChartInstance.options.scales.y.grid || {}),
                    color: chartGrid,
                };
                categoryHistoryChartInstance.options.plugins.legend.labels = {
                    ...(categoryHistoryChartInstance.options.plugins.legend.labels || {}),
                    color: chartText,
                };
                categoryHistoryChartInstance.options.plugins.tooltip = {
                    ...(categoryHistoryChartInstance.options.plugins.tooltip || {}),
                    backgroundColor: tooltipBackground,
                    titleColor: tooltipText,
                    bodyColor: tooltipText,
                    borderColor: tooltipBorder,
                    borderWidth: 1,
                };
                categoryHistoryChartInstance.update('none');
            }

            if (categoryChartInstance) {
                categoryChartInstance.options.scales.x.ticks = {
                    ...(categoryChartInstance.options.scales.x.ticks || {}),
                    color: chartText,
                };
                categoryChartInstance.options.scales.x.grid = {
                    ...(categoryChartInstance.options.scales.x.grid || {}),
                    color: chartGrid,
                };
                categoryChartInstance.options.scales.y.ticks = {
                    ...(categoryChartInstance.options.scales.y.ticks || {}),
                    color: chartText,
                };
                categoryChartInstance.options.plugins.tooltip = {
                    ...(categoryChartInstance.options.plugins.tooltip || {}),
                    backgroundColor: tooltipBackground,
                    titleColor: tooltipText,
                    bodyColor: tooltipText,
                    borderColor: tooltipBorder,
                    borderWidth: 1,
                };
                categoryChartInstance.update('none');
            }
        }

        function applyTheme(theme, options = {}) {
            const persist = options.persist !== false;
            const nextTheme = theme === 'dark' ? 'dark' : 'light';
            document.documentElement.dataset.theme = nextTheme;

            if (persist) {
                try {
                    localStorage.setItem(themeStorageKey, nextTheme);
                } catch (error) {
                }
            }

            updateThemeToggle(nextTheme);
            applyThemeToCharts();
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

            const theme = getCurrentTheme();
            const hue = Math.abs(hash) % 360;
            const saturation = theme === 'dark' ? 76 : 82;
            const lightness = theme === 'dark' ? 60 : 46;

            return `hsla(${hue}, ${saturation}%, ${lightness}%, ${alpha})`;
        }

        function makeDistinctSeriesColor(index, alpha, hueOffset = 210) {
            const hue = (hueOffset + (Math.max(0, index) * 137.508)) % 360;
            return `hsla(${hue.toFixed(1)}, 82%, 52%, ${alpha})`;
        }

        function makeInstanceColor(instanceName, alpha) {
            const index = instanceNames.indexOf(instanceName);
            return makeDistinctSeriesColor(index >= 0 ? index : 0, alpha);
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
            const pointsByInstance = new Map(instanceNames.map(instanceName => [instanceName, new Map()]));

            for (const item of items) {
                const instancePoints = pointsByInstance.get(item.instance_name);
                if (instancePoints && !instancePoints.has(item.timestamp)) {
                    instancePoints.set(item.timestamp, item);
                }
            }

            const downloadDatasets = instanceNames.map(instanceName => {
                const instancePoints = pointsByInstance.get(instanceName);

                return {
                    label: `${instanceName} ↓ Download`,
                    data: timestamps.map(timestamp => {
                        const row = instancePoints.get(timestamp);
                        return row ? Number(row.dl_speed) || 0 : 0;
                    }),
                    backgroundColor: makeInstanceColor(instanceName, 0.05),
                    borderColor: makeInstanceColor(instanceName, 0.72),
                    borderWidth: 1.5,
                    borderDash: [7, 5],
                    fill: true,
                    tension: 0.24,
                    stack: 'download',
                    pointRadius: 0,
                    pointHoverRadius: 4,
                };
            });

            const uploadDatasets = instanceNames.map(instanceName => {
                const instancePoints = pointsByInstance.get(instanceName);

                return {
                    label: `${instanceName} ↑ Upload`,
                    data: timestamps.map(timestamp => {
                        const row = instancePoints.get(timestamp);
                        return row ? Number(row.up_speed) || 0 : 0;
                    }),
                    backgroundColor: makeInstanceColor(instanceName, 0.12),
                    borderColor: makeInstanceColor(instanceName, 0.96),
                    borderWidth: 1.8,
                    fill: true,
                    tension: 0.24,
                    stack: 'upload',
                    pointRadius: 0,
                    pointHoverRadius: 4,
                };
            });

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

        function buildCategoryUploadHistoryModel(history) {
            const timestamps = Array.isArray(history?.timestamps) ? history.timestamps : [];
            const labels = timestamps.map(formatTimeLabel);
            const series = Array.isArray(history?.series) ? history.series : [];
            const datasets = series.map((item, index) => {
                const rawData = Array.isArray(item.data)
                    ? item.data.map(value => Number(value) || 0)
                    : [];

                return {
                    label: item.category,
                    data: smoothCategoryHistoryValues(rawData),
                    $rawData: rawData,
                    borderColor: makeDistinctSeriesColor(index, 0.92, 24),
                    backgroundColor: makeDistinctSeriesColor(index, 0.08, 24),
                    borderWidth: 1.8,
                    fill: false,
                    tension: 0.5,
                    cubicInterpolationMode: 'monotone',
                    spanGaps: true,
                    pointRadius: 0,
                    pointHoverRadius: 4,
                };
            });

            return {
                timestamps,
                labels,
                datasets,
            };
        }

        function smoothCategoryHistoryValues(values, radius = 3) {
            if (values.length < 3) {
                return [...values];
            }

            return values.map((value, index) => {
                let weightedTotal = 0;
                let totalWeight = 0;

                for (let offset = -radius; offset <= radius; offset++) {
                    const sourceIndex = index + offset;
                    if (sourceIndex < 0 || sourceIndex >= values.length) {
                        continue;
                    }

                    const weight = radius + 1 - Math.abs(offset);
                    weightedTotal += values[sourceIndex] * weight;
                    totalWeight += weight;
                }

                return totalWeight > 0 ? weightedTotal / totalWeight : value;
            });
        }

        function getCategoryHistoryRawValue(dataset, dataIndex) {
            const values = Array.isArray(dataset?.$rawData) ? dataset.$rawData : dataset?.data;
            return Number(values?.[dataIndex]) || 0;
        }

        function getCategoryTooltipDatasetIndexes(chart, dataIndex, limit = 15) {
            const anchorY = Number.isFinite(chart.$tooltipAnchorY)
                ? chart.$tooltipAnchorY
                : chart.chartArea.top;
            const cached = chart.$tooltipRanking;
            if (cached && cached.dataIndex === dataIndex && cached.anchorY === anchorY) {
                return cached.indexes;
            }

            const ranked = chart.data.datasets
                .map((dataset, index) => ({
                    index,
                    value: getCategoryHistoryRawValue(dataset, dataIndex),
                }))
                .filter(item => chart.isDatasetVisible(item.index))
                .sort((left, right) => (right.value - left.value) || (left.index - right.index));
            let anchorRank = 0;
            let nearestDistance = Number.POSITIVE_INFINITY;

            ranked.forEach((item, rank) => {
                const plottedValue = Number(chart.data.datasets[item.index]?.data?.[dataIndex]) || 0;
                const plottedY = chart.scales.y.getPixelForValue(plottedValue);
                const distance = Math.abs(plottedY - anchorY);

                if (distance < nearestDistance) {
                    nearestDistance = distance;
                    anchorRank = rank;
                }
            });

            const maximumStart = Math.max(0, ranked.length - limit);
            const windowStart = Math.min(
                maximumStart,
                Math.max(0, anchorRank - Math.floor(limit / 2))
            );
            const indexes = new Set(
                ranked.slice(windowStart, windowStart + limit).map(item => item.index)
            );
            chart.$tooltipRanking = { dataIndex, anchorY, indexes };

            return indexes;
        }

        const categoryTooltipWindowPlugin = {
            id: 'categoryTooltipWindow',
            beforeEvent(chart, args) {
                if (args.event.type === 'mousemove') {
                    chart.$tooltipAnchorY = args.event.y;
                    chart.$tooltipRanking = null;
                } else if (args.event.type === 'mouseout') {
                    chart.$tooltipAnchorY = null;
                    chart.$tooltipRanking = null;
                }
            },
        };

        function renderCategoryHistoryLegend() {
            const legend = document.getElementById('categoryHistoryLegend');
            if (!legend || !categoryHistoryChartInstance) {
                return;
            }

            legend.innerHTML = categoryHistoryChartInstance.data.datasets.map((dataset, index) => `
                <button type="button" data-dataset-index="${index}" aria-pressed="${categoryHistoryChartInstance.isDatasetVisible(index) ? 'true' : 'false'}">
                    <span class="series-swatch" style="--series-color: ${dataset.borderColor}"></span>
                    <span>${escapeHtml(dataset.label)}</span>
                </button>
            `).join('');

            legend.querySelectorAll('button[data-dataset-index]').forEach(button => {
                const index = Number(button.dataset.datasetIndex);
                button.classList.toggle('is-hidden', !categoryHistoryChartInstance.isDatasetVisible(index));

                button.addEventListener('click', () => {
                    const nextVisible = !categoryHistoryChartInstance.isDatasetVisible(index);
                    categoryHistoryChartInstance.setDatasetVisibility(index, nextVisible);
                    categoryHistoryChartInstance.$tooltipRanking = null;
                    button.classList.toggle('is-hidden', !nextVisible);
                    button.setAttribute('aria-pressed', nextVisible ? 'true' : 'false');
                    categoryHistoryChartInstance.update('none');
                });
            });
        }

        function setCategoryHistoryLegendVisible(visible) {
            const legend = document.getElementById('categoryHistoryLegend');
            const button = document.getElementById('categoryHistoryLegendToggle');
            if (!legend || !button) {
                return;
            }

            legend.hidden = !visible;
            button.textContent = visible ? 'Скрыть легенду' : 'Показать легенду';
            button.setAttribute('aria-expanded', visible ? 'true' : 'false');
        }

        function renderCategoryUploadHistoryChart() {
            const model = buildCategoryUploadHistoryModel(historyPayload.category_upload_history || {});
            const context = document.getElementById('categoryHistoryChart').getContext('2d');

            if (!categoryHistoryChartInstance) {
                categoryHistoryChartInstance = new Chart(context, {
                    type: 'line',
                    data: {
                        labels: model.labels,
                        datasets: model.datasets,
                    },
                    options: {
                        animation: false,
                        maintainAspectRatio: false,
                        responsive: true,
                        interaction: {
                            intersect: false,
                            mode: 'nearest',
                            axis: 'xy',
                        },
                        scales: {
                            x: {
                                grid: {
                                    display: false,
                                },
                            },
                            y: {
                                beginAtZero: true,
                                ticks: {
                                    callback: value => formatSpeed(value),
                                },
                            },
                        },
                        plugins: {
                            legend: {
                                display: false,
                            },
                            tooltip: {
                                mode: 'index',
                                intersect: false,
                                filter: context => getCategoryTooltipDatasetIndexes(
                                    context.chart,
                                    context.dataIndex,
                                    15
                                ).has(context.datasetIndex),
                                itemSort: (left, right) => (
                                    getCategoryHistoryRawValue(right.dataset, right.dataIndex)
                                    - getCategoryHistoryRawValue(left.dataset, left.dataIndex)
                                ),
                                callbacks: {
                                    title: items => {
                                        const timestamp = categoryHistoryChartInstance?.$timestamps?.[items[0]?.dataIndex ?? -1];
                                        return formatTimestamp(timestamp);
                                    },
                                    label: context => `${context.dataset.label}: ${formatSpeed(
                                        getCategoryHistoryRawValue(context.dataset, context.dataIndex)
                                    )}`,
                                },
                            },
                        },
                    },
                    plugins: [categoryTooltipWindowPlugin],
                });
            } else {
                categoryHistoryChartInstance.data.labels = model.labels;
                categoryHistoryChartInstance.data.datasets = model.datasets;
                categoryHistoryChartInstance.update('none');
            }

            categoryHistoryChartInstance.$timestamps = model.timestamps;
            categoryHistoryChartInstance.$tooltipRanking = null;
            renderCategoryHistoryLegend();
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
            renderCategoryUploadHistoryChart();
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
                    throw new Error(payload.error || payload.meta?.refresh?.error || 'Не удалось получить текущие данные');
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

        function resolveTimestampFromEvent(event, chartInstance, interactionMode = 'nearest') {
            if (!chartInstance || !chartInstance.$timestamps) {
                return null;
            }

            const interactionOptions = interactionMode === 'index'
                ? { intersect: false, axis: 'x' }
                : { intersect: false };
            const points = chartInstance.getElementsAtEventForMode(
                event,
                interactionMode,
                interactionOptions,
                false
            );
            if (!points.length) {
                return null;
            }

            return chartInstance.$timestamps[points[0].index] || null;
        }

        function attachTimelineInteractions(canvasId, getChartInstance, interactionMode = 'nearest') {
            const canvas = document.getElementById(canvasId);

            canvas.addEventListener('mousemove', event => {
                if (pinnedTimestamp) {
                    return;
                }

                const timestamp = resolveTimestampFromEvent(event, getChartInstance(), interactionMode);
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
                const timestamp = resolveTimestampFromEvent(event, getChartInstance(), interactionMode);
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

        function attachHistoryInteractions() {
            attachTimelineInteractions('historyChart', () => historyChartInstance);
            attachTimelineInteractions('categoryHistoryChart', () => categoryHistoryChartInstance, 'index');
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
            updateThemeToggle(getCurrentTheme());
            renderHistoryChart();
            renderCategoryUploadHistoryChart();
            renderCategoryChart(currentData.categories);
            applyThemeToCharts();
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
            const themeToggleButton = document.getElementById('themeToggleButton');
            const categoryHistoryLegendToggle = document.getElementById('categoryHistoryLegendToggle');

            const storedAutoRefresh = localStorage.getItem('qbitStatsAutoRefresh');
            const autoRefreshEnabled = storedAutoRefresh === null ? true : storedAutoRefresh === 'true';
            autoRefreshCheckbox.checked = autoRefreshEnabled;
            configureAutoRefresh(autoRefreshEnabled);

            refreshButton.addEventListener('click', () => {
                refreshDashboard({ force: true });
            });

            themeToggleButton.addEventListener('click', () => {
                applyTheme(getCurrentTheme() === 'dark' ? 'light' : 'dark');
            });

            categoryHistoryLegendToggle.addEventListener('click', () => {
                const legend = document.getElementById('categoryHistoryLegend');
                setCategoryHistoryLegendVisible(legend.hidden);
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
