<?php
$config = require __DIR__.'/config.php';
require __DIR__.'/data_functions.php';

// Подключение к БД
$db = new SQLite3($config['db_path']);
$db->exec('PRAGMA journal_mode=WAL');

// Создание таблиц, если они не существуют
$db->exec('CREATE TABLE IF NOT EXISTS instances (
    name TEXT PRIMARY KEY,
    dl_speed INTEGER,
    up_speed INTEGER,
    dl_session INTEGER,
    up_session INTEGER,
    last_update DATETIME
)');

$db->exec('CREATE TABLE IF NOT EXISTS categories (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    instance_name TEXT,
    category TEXT,
    active_torrents INTEGER,
    dl_speed INTEGER,
    up_speed INTEGER,
    total_size INTEGER,
    uploaded_session INTEGER,
    last_update DATETIME,
    UNIQUE(instance_name, category)
)');

$db->exec('CREATE TABLE IF NOT EXISTS speed_history (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    instance_name TEXT,
    dl_speed INTEGER,
    up_speed INTEGER,
    timestamp DATETIME
)');

$db->exec('CREATE TABLE IF NOT EXISTS category_history (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    instance_name TEXT,
    category TEXT,
    active_torrents INTEGER,
    dl_speed INTEGER,
    up_speed INTEGER,
    total_size INTEGER,
    uploaded_session INTEGER,
    timestamp DATETIME
)');



$current_data = get_current_data($db, $config); // Теперь с параметрами

function get_history_data($hours = 24) {
    global $db;
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
    return $data;
}

$history_data = get_history_data(6); // Получить данные за последние 6 часов

// Функции форматирования
function format_speed($bytes) {
    $units = ['B/s', 'KB/s', 'MB/s', 'GB/s'];
    $bytes = max($bytes, 0);
    $pow = $bytes ? floor(log($bytes)/log(1024)) : 0;
    return round($bytes/pow(1024,$pow),2).' '.$units[min($pow, count($units)-1)];
}

function format_size($bytes) {
    $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
    $bytes = max($bytes, 0);
    $pow = $bytes ? floor(log($bytes)/log(1024)) : 0;
    return round($bytes/pow(1024,$pow),2).' '.$units[min($pow, count($units)-1)];
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>qBittorrent Monitor</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        .chart-container {
            margin: 30px 0;
            height: 400px;
            width: 90%;
            background: #fff;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            padding: 15px;
        }
        table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background-color: #f2f2f2; }
        .refresh-controls { margin: 20px 0; }
        .last-update { color: #666; font-size: 0.9em; }
        .status { padding: 10px; margin: 10px 0; border-radius: 4px; display: none; position: fixed; background: #fcffaa;}
        .status.success { background: #d4edda; display: block; }
        .status.error { background: #f8d7da; display: block; }
        .category-table { margin-top: 30px; }
        .sortable {
            cursor: pointer;
            position: relative;
            padding-right: 20px !important;
        }
        .sortable:hover {
            background-color: #f0f0f0;
        }
        .sort-arrow {
            position: absolute;
            right: 8px;
            top: 50%;
            transform: translateY(-50%);
            width: 0;
            height: 0;
            border-left: 5px solid transparent;
            border-right: 5px solid transparent;
            border-bottom: 5px solid #ccc;
            opacity: 0;
        }
        .sort-arrow.asc {
            opacity: 1;
        }
        .sort-arrow.desc {
            opacity: 1;
            border-bottom: none;
            border-top: 5px solid #ccc;
        }
        #charts {
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
        }
        #charts > div {
            flex: 1;
            min-width: 400px;
        }
        /* анимация обновления  */
        .refresh-indicator {
            display: none;
            align-items: center;
            gap: 8px;
            position: absolute;
            margin-top: 10px;
            color: #666;
            font-size: 0.9em;
        }

        .refresh-indicator.visible {
            display: flex;
        }

        .refresh-indicator .spinner {
            width: 16px;
            height: 16px;
            border: 2px solid rgba(0, 0, 0, 0.1);
            border-radius: 50%;
            border-top-color: #4CAF50;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        .summary-table table {
            width: 100%;
            border-collapse: collapse;
        }
        .summary-table th, .summary-table td {
            padding: 8px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }
        .summary-table th {
            background-color: #e9ecef;
        }
    </style>
</head>
<body>
    <h1>qBittorrent Monitoring</h1>
    
    <div class="refresh-controls">
        <button id="refresh-btn">Обновить сейчас</button>
        <label>
            <input type="checkbox" id="auto-refresh"> 
            Автообновление (каждые 5 минут)
        </label>
        <span class="last-update">Последнее обновление: <?= $current_data['last_update'] ?></span>
        <!-- Добавьте этот элемент -->
        <div id="refresh-indicator" class="refresh-indicator">
            <div class="spinner"></div>
            <span class="text">Обновление...</span>
        </div>
    </div>
    
    <div id="status" class="status"></div>
    
    <div id="charts">
        <div>
            <h2>Скорости по инстансам</h2>
            <div class="chart-container">
                <canvas id="historyChart"></canvas>
            </div>
        </div>
        <div class="right_chart">
            <h2>Статистика по категориям</h2>
            <div class="chart-container">
                <canvas id="categoryChart"></canvas>
            </div>
        </div>
    </div>
    
    <div class="summary-table">
        <h3>Суммарная статистика по всем категориям</h3>
        <table id="summaryTable">
            <thead>
                <tr>
                    <th>Всего категорий</th>
                    <th>Скорость загрузки</th>
                    <th>Скорость отдачи</th>
                    <th>Активные раздачи</th>
                    <th>Общий объем</th>
                    <th>Отдано за сеанс</th>
                    <th>Всего отдано</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td><?= count($current_data['categories']) ?></td>
                    <td><?= format_speed(array_sum(array_column($current_data['categories'], 'dl_speed'))) ?></td>
                    <td><?= format_speed(array_sum(array_column($current_data['categories'], 'up_speed'))) ?></td>
                    <td><?= array_sum(array_column($current_data['categories'], 'active_torrents')) ?></td>
                    <td><?= format_size(array_sum(array_column($current_data['categories'], 'total_size'))) ?></td>
                    <td><?= format_size(array_sum(array_column($current_data['categories'], 'uploaded_session'))) ?></td>
                    <td><?= format_size(array_sum(array_column($current_data['categories'], 'uploaded_total'))) ?></td>
                </tr>
            </tbody>
        </table>
    </div>

    <div class="category-table">
        <h3>Детализация по категориям</h3>
        <table id="categoriesTable">
            <thead>
                <tr>
                    <th class="sortable" data-column="category">Категория <span class="sort-arrow"></span></th>
                    <th class="sortable" data-column="dl_speed">Скорость загрузки <span class="sort-arrow"></span></th>
                    <th class="sortable" data-column="up_speed">Скорость отдачи <span class="sort-arrow"></span></th>
                    <th class="sortable" data-column="active_torrents">Активные раздачи <span class="sort-arrow"></span></th>
                    <th class="sortable" data-column="total_size">Общий объем <span class="sort-arrow"></span></th>
                    <th class="sortable" data-column="uploaded_session">Отдано за сеанс <span class="sort-arrow"></span></th>
                    <th class="sortable" data-column="uploaded_total">Всего отдано <span class="sort-arrow"></span></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($current_data['categories'] as $category): ?>
                <tr>
                    <td><?= htmlspecialchars($category['category']) ?></td>
                    <td data-sort-value="<?= $category['dl_speed'] ?>"><?= format_speed($category['dl_speed']) ?></td>
                    <td data-sort-value="<?= $category['up_speed'] ?>"><?= format_speed($category['up_speed']) ?></td>
                    <td data-sort-value="<?= $category['active_torrents'] ?>"><?= $category['active_torrents'] ?></td>
                    <td data-sort-value="<?= $category['total_size'] ?? 0 ?>"><?= format_size($category['total_size'] ?? 0) ?></td>
                    <td data-sort-value="<?= $category['uploaded_session'] ?? 0 ?>"><?= format_size($category['uploaded_session'] ?? 0) ?></td>
                    <td data-sort-value="<?= $category['uploaded_total'] ?? 0 ?>"><?= format_size($category['uploaded_total'] ?? 0) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    
    <script>
        // Глобальные переменные для хранения экземпляров графиков
        let historyChartInstance;
        let categoryChartInstance;
        let currentData = <?= json_encode($current_data) ?>;
        let historyData = <?= json_encode($history_data) ?> || [];
        let instances = <?= json_encode(array_column($config['instances'], 'name')) ?>;
        
        // Функция для экранирования HTML
        function escapeHtml(unsafe) {
            return unsafe
                .replace(/&/g, "&amp;")
                .replace(/</g, "&lt;")
                .replace(/>/g, "&gt;")
                .replace(/"/g, "&quot;")
                .replace(/'/g, "&#039;");
        }
        
        // Функция форматирования скорости для tooltip
        function formatSpeedTooltip(bytes) {
            const units = ['B/s', 'KB/s', 'MB/s', 'GB/s'];
            bytes = Math.abs(bytes);
            if (bytes === 0) return '0 B/s';
            const i = Math.min(Math.floor(Math.log(bytes) / Math.log(1024)), units.length - 1);
            return `${(bytes / Math.pow(1024, i)).toFixed(2)} ${units[i]}`;
        }
        
        // Функция форматирования размера для tooltip
        function formatSizeTooltip(bytes) {
            const units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
            bytes = Math.abs(bytes);
            if (bytes === 0) return '0 B';
            const i = Math.min(Math.floor(Math.log(bytes) / Math.log(1024)), units.length - 1);
            return `${(bytes / Math.pow(1024, i)).toFixed(2)} ${units[i]}`;
        }
        
        // Функция сортировки таблицы
        function sortTable(table, column, direction) {
            const tbody = table.querySelector('tbody');
            const rows = Array.from(tbody.querySelectorAll('tr'));
            
            rows.sort((a, b) => {
                let aValue, bValue;
                
                if (column === 'category') {
                    aValue = a.cells[0].textContent.trim();
                    bValue = b.cells[0].textContent.trim();
                    return direction === 'asc' 
                        ? aValue.localeCompare(bValue) 
                        : bValue.localeCompare(aValue);
                } else {
                    const colIndex = Array.from(table.querySelectorAll('th')).findIndex(th => th.dataset.column === column);
                    aValue = parseFloat(a.cells[colIndex].getAttribute('data-sort-value')) || 0;
                    bValue = parseFloat(b.cells[colIndex].getAttribute('data-sort-value')) || 0;
                    return direction === 'asc' ? aValue - bValue : bValue - aValue;
                }
            });
            
            // Удаляем существующие строки
            while (tbody.firstChild) {
                tbody.removeChild(tbody.firstChild);
            }
            
            // Добавляем отсортированные строки
            rows.forEach(row => tbody.appendChild(row));
            
            // Обновляем индикаторы сортировки
            table.querySelectorAll('.sort-arrow').forEach(arrow => {
                arrow.classList.remove('asc', 'desc');
            });
            
            const header = table.querySelector(`th[data-column="${column}"]`);
            if (header) {
                const arrow = header.querySelector('.sort-arrow');
                if (arrow) {
                    arrow.classList.add(direction);
                }
            }
        }

        // Функция для обновления сводной таблицы
        function updateSummaryTable(categories) {
            const totalCategories = categories.length;
            const totalDlSpeed = categories.reduce((sum, cat) => sum + (cat.dl_speed || 0), 0);
            const totalUpSpeed = categories.reduce((sum, cat) => sum + (cat.up_speed || 0), 0);
            const totalActive = categories.reduce((sum, cat) => sum + (cat.active_torrents || 0), 0);
            const totalSize = categories.reduce((sum, cat) => sum + (cat.total_size || 0), 0);
            const totalUploaded = categories.reduce((sum, cat) => sum + (cat.uploaded_session || 0), 0);
            const totalUploadedTotal = categories.reduce((sum, cat) => sum + (cat.uploaded_total || 0), 0);

            const summaryTable = document.getElementById('summaryTable');
            if (summaryTable) {
                summaryTable.querySelector('tbody').innerHTML = `
                    <tr>
                        <td>${totalCategories}</td>
                        <td>${formatSpeedTooltip(totalDlSpeed)}</td>
                        <td>${formatSpeedTooltip(totalUpSpeed)}</td>
                        <td>${totalActive}</td>
                        <td>${formatSizeTooltip(totalSize)}</td>
                        <td>${formatSizeTooltip(totalUploaded)}</td>
                        <td>${formatSizeTooltip(totalUploadedTotal)}</td>
                    </tr>
                `;
            }
        }
        
        // Функция обновления таблицы категорий
        function updateCategoriesTable(categories) {
            const tbody = document.querySelector('#categoriesTable tbody');
            tbody.innerHTML = categories.map(category => `
                <tr>
                    <td>${escapeHtml(category.category)}</td>
                    <td data-sort-value="${category.dl_speed}">${formatSpeedTooltip(category.dl_speed)}</td>
                    <td data-sort-value="${category.up_speed}">${formatSpeedTooltip(category.up_speed)}</td>
                    <td data-sort-value="${category.active_torrents}">${category.active_torrents}</td>
                    <td data-sort-value="${category.total_size || 0}">${formatSizeTooltip(category.total_size || 0)}</td>
                    <td data-sort-value="${category.uploaded_session || 0}">${formatSizeTooltip(category.uploaded_session || 0)}</td>
                    <td data-sort-value="${category.uploaded_total || 0}">${formatSizeTooltip(category.uploaded_total || 0)}</td>
                </tr>
            `).join('');
            
            // Восстанавливаем сортировку, если она была
            const savedSort = localStorage.getItem('categoriesTableSort');
            if (savedSort) {
                try {
                    const { column, direction } = JSON.parse(savedSort);
                    sortTable(document.getElementById('categoriesTable'), column, direction);
                } catch (e) {
                    console.error('Error loading sort state:', e);
                }
            }
        }
        
        // Функция инициализации графика категорий
        function initCategoryChart(categories) {
            const ctx = document.getElementById('categoryChart').getContext('2d');
            
            if (categoryChartInstance) {
                categoryChartInstance.data.labels = categories.map(c => c.category);
                categoryChartInstance.data.datasets[0].data = categories.map(c => c.up_speed);
                categoryChartInstance.update();
            } else {
                categoryChartInstance = new Chart(ctx, {
                    type: 'bar',
                    data: {
                        labels: categories.map(c => c.category),
                        datasets: [{
                            label: 'Upload Speed',
                            data: categories.map(c => c.up_speed),
                            backgroundColor: 'rgba(75, 192, 192, 0.2)',
                            borderColor: 'rgba(75, 192, 192, 1)',
                            borderWidth: 2
                        }]
                    },
                    options: {
                        responsive: true,
                        scales: {
                            y: {
                                beginAtZero: true,
                                ticks: {
                                    callback: function(value) {
                                        return formatSpeedTooltip(value);
                                    }
                                }
                            }
                        },
                        plugins: {
                            tooltip: {
                                callbacks: {
                                    label: function(context) {
                                        return `${context.dataset.label}: ${formatSpeedTooltip(context.raw)}`;
                                    }
                                }
                            }
                        }
                    }
                });
            }
        }
        
        // Функция инициализации графика истории
        function initHistoryChart(data = historyData) {
            const ctx = document.getElementById('historyChart').getContext('2d');
            
            // Если график уже существует - просто обновляем данные
            if (historyChartInstance) {
                updateHistoryChart(data);
                return;
            }
            
            // Группировка данных по времени
            const timestamps = [...new Set(historyData.map(item => item.timestamp))].sort();
            
            // Создаем наборы данных для каждого инстанса (Download)
            const dlDatasets = instances.map(instance => {
                return {
                    label: `${instance} ↓ Download`,
                    data: timestamps.map(time => {
                        const record = historyData.find(item => 
                            item.instance_name === instance && item.timestamp === time);
                        return record ? record.dl_speed : 0;
                    }),
                    backgroundColor: getRandomColor(instance, 1),
                    borderColor: getRandomColor(instance, 1),
                    borderWidth: 1,
                    fill: true,
                    tension: 0.4,
                    stack: 'download'
                };
            });
            
            // Создаем наборы данных для каждого инстанса (Upload)
            const upDatasets = instances.map(instance => {
                return {
                    label: `${instance} ↑ Upload`,
                    data: timestamps.map(time => {
                        const record = historyData.find(item => 
                            item.instance_name === instance && item.timestamp === time);
                        return record ? record.up_speed : 0;
                    }),
                    backgroundColor: getRandomColor(instance, 0.2),
                    borderColor: getRandomColor(instance, 0.5),
                    borderWidth: 1,
                    fill: true,
                    tension: 0.4,
                    stack: 'upload'
                };
            });
            
            // Функция для генерации цветов
            function getRandomColor(base, opacity) {
                const hash = [...base].reduce((acc, char) => char.charCodeAt(0) + acc, 0);
                const r = (hash * 13) % 255;
                const g = (hash * 25) % 255;
                const b = (hash * 38) % 255;
                return `rgba(${r}, ${g}, ${b}, ${opacity})`;
            }
            
            historyChartInstance = new Chart(ctx, {
                type: 'line',
                data: {
                    labels: timestamps.map(time => new Date(time).toLocaleTimeString()),
                    datasets: [...dlDatasets, ...upDatasets]
                },
                options: {
                    responsive: true,
                    interaction: {
                        intersect: false,
                        mode: 'index'
                    },
                    scales: {
                        x: {
                            stacked: true,
                            title: { display: true, text: 'Time' },
                            grid: {
                                display: false
                            }
                        },
                        y: {
                            stacked: true,
                            beginAtZero: true,
                            title: { display: true, text: 'Speed (B/s)' },
                            ticks: {
                                callback: function(value) {
                                    return formatSpeedTooltip(value);
                                }
                            }
                        }
                    },
                    plugins: {
                        title: {
                            display: true,
                            text: 'История скоростей (Stacked Area Chart)'
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    return `${context.dataset.label}: ${formatSpeedTooltip(context.raw)}`;
                                },
                                footer: function(tooltipItems) {
                                    let dlTotal = 0;
                                    let upTotal = 0;
                                    
                                    tooltipItems.forEach(item => {
                                        if (item.dataset.stack === 'download') {
                                            dlTotal += item.raw;
                                        } else {
                                            upTotal += item.raw;
                                        }
                                    });
                                    
                                    return [
                                        `Total Download: ${formatSpeedTooltip(dlTotal)}`,
                                        `Total Upload: ${formatSpeedTooltip(upTotal)}`
                                    ];
                                }
                            }
                        },
                        legend: {
                            position: 'top',
                            labels: {
                                boxWidth: 12,
                                usePointStyle: true
                            }
                        }
                    },
                    elements: {
                        point: {
                            radius: 0,
                            hoverRadius: 5
                        }
                    }
                }
            });
            
            // Добавляем обработчик событий мыши
            let lastProcessedTimestamp = null;
            let activeRequestController = null;
            const responseCache = {};

            // Обновляем глобальную переменную historyData при получении новых данных
            window.updateHistoryData = function(newData) {
                historyData = newData;
                
                // Также обновляем кэш для уже загруженных точек
                Object.keys(responseCache).forEach(timestamp => {
                    if (!newData.some(item => item.timestamp === timestamp)) {
                        // Удаляем устаревшие данные из кэша
                        delete responseCache[timestamp];
                    }
                });
            };

            // Функция для обработки наведения на график
            const handleChartHover = async (e) => {
                if (!historyChartInstance) return;
                
                const points = historyChartInstance.getElementsAtEventForMode(e, 'nearest', { intersect: false }, true);
                if (points.length === 0) return;
                
                const pointIndex = points[0].index;
                const currentLabel = historyChartInstance.data.labels[pointIndex];
                
                // Находим полный timestamp по метке времени
                const originalDataPoint = historyData.find(item => 
                    new Date(item.timestamp).toLocaleTimeString() === currentLabel
                );
                
                if (!originalDataPoint || !originalDataPoint.timestamp) return;
                
                const currentTimestamp = originalDataPoint.timestamp;
                
                // Если timestamp не изменился - ничего не делаем
                if (lastProcessedTimestamp === currentTimestamp) return;
                
                lastProcessedTimestamp = currentTimestamp;
                
                // Проверяем кэш
                if (responseCache[currentTimestamp]) {
                    updateCategoriesTable(responseCache[currentTimestamp]);
                    updateSummaryTable(responseCache[currentTimestamp]); // Добавим эту строку
                    initCategoryChart(responseCache[currentTimestamp]);
                    return;
                }
                
                // Отменяем предыдущий запрос, если он есть
                if (activeRequestController) {
                    activeRequestController.abort();
                }
                activeRequestController = new AbortController();
                
                try {
                    const response = await fetch(`get_category_history.php?timestamp=${encodeURIComponent(currentTimestamp)}`, {
                        signal: activeRequestController.signal
                    });
                    
                    if (!response.ok) throw new Error('Network error');
                    
                    const historicalData = await response.json();
                    
                    // Сохраняем в кэш
                    responseCache[currentTimestamp] = historicalData;
                    
                    updateCategoriesTable(historicalData);
                    initCategoryChart(historicalData);
                    updateSummaryTable(historicalData); // Добавим эту строку
                    
                } catch (error) {
                    if (error.name !== 'AbortError') {
                        console.error('Error fetching historical data:', error);
                        updateCategoriesTable(currentData.categories);
                        initCategoryChart(currentData.categories);
                        updateSummaryTable(currentData.categories); // Добавим эту строку
                    }
                } finally {
                    activeRequestController = null;
                }
            };

            // Добавьте обработчики событий
            document.getElementById('historyChart').addEventListener('mousemove', (e) => {
                if (!historyChartInstance) return;
                
                const points = historyChartInstance.getElementsAtEventForMode(e, 'nearest', { intersect: false }, true);
                if (points.length === 0) return;
                
                const pointIndex = points[0].index;
                const currentLabel = historyChartInstance.data.labels[pointIndex];
                
                // Находим полный timestamp для этой точки
                const originalDataPoint = historyData.find(item => 
                    new Date(item.timestamp).toLocaleTimeString() === currentLabel
                );
                
                // Обновляем заголовки только если нашли timestamp
                if (originalDataPoint?.timestamp) {
                    const date = new Date(originalDataPoint.timestamp);
                    const timeStr = `${date.getFullYear()}-${(date.getMonth()+1).toString().padStart(2,'0')}-${date.getDate().toString().padStart(2,'0')} ${date.getHours().toString().padStart(2,'0')}:${date.getMinutes().toString().padStart(2,'0')}:${date.getSeconds().toString().padStart(2,'0')}`;
                    
                    document.querySelector('.category-table h3').textContent = `Детализация по категориям (${timeStr})`;
                    document.querySelector('#charts .right_chart h2').textContent = `Статистика по категориям (${timeStr})`;
                }
                
                // Вызываем ваш основной обработчик
                handleChartHover(e);
            });
            document.getElementById('historyChart').addEventListener('mouseleave', () => {
                // Возвращаем заголовки без времени
                document.querySelector('.category-table h3').textContent = 'Детализация по категориям';
                document.querySelector('#charts .right_chart h2').textContent = 'Статистика по категориям';
                
                // Дополнительно вызываем ваш handleChartHover если нужно
                if (typeof handleChartHover === 'function') {
                    // handleChartHover();
                }
            });
            
            // Восстанавливаем исходные данные при уходе курсора
            document.getElementById('historyChart').addEventListener('mouseout', () => {
                updateCategoriesTable(currentData.categories);
                initCategoryChart(currentData.categories);
                updateSummaryTable(currentData.categories); // Добавим эту строку
            });
        }
        
        // Инициализация при загрузке
        $(document).ready(function() {
            const status = $('#status');
            const lastUpdate = $('.last-update');
            const autoRefreshCheckbox = $('#auto-refresh');
            let refreshInterval;
            let isFirstLoad = true;
            
            // Загружаем состояние из localStorage
            const savedAutoRefreshState = localStorage.getItem('autoRefreshEnabled');
            if (savedAutoRefreshState !== null) {
                autoRefreshCheckbox.prop('checked', savedAutoRefreshState === 'true');
            }
            
            function updateStatus(message, type) {
                status.removeClass('loading success error')
                      .addClass(type)
                      .text(message)
                      .fadeIn().delay(3000).fadeOut();
            }
            
            // Функция для обновления данных
            let lastKnownUpdate = '<?= $current_data["last_update"] ?>'; // Инициализируем текущим временем

            async function refreshData() {
                const indicator = document.getElementById('refresh-indicator');
                if (!indicator) return;

                try {
                    indicator.classList.add('visible');
                    
                    // 1. Проверяем время последнего обновления с If-Modified-Since
                    const timeCheck = await fetch('get_last_update.php', {
                        headers: {
                            'If-Modified-Since': lastKnownUpdate
                        }
                    });
                    
                    // Если данные не изменились (304) - выходим
                    if (timeCheck.status === 304) {
                        indicator.classList.remove('visible');
                        return;
                    }
                    
                    // Если данные изменились - получаем новый timestamp
                    const { last_update } = await timeCheck.json();
                    
                    // 2. Загружаем ТОЛЬКО новые данные (с If-Modified-Since)
                    const dataResponse = await fetch('get_current_data.php', {
                        headers: {
                            'If-Modified-Since': lastKnownUpdate
                        }
                    });
                    
                    // Если основные данные не изменились (304) - обновляем только историю
                    if (dataResponse.status === 304) {
                        const historyResponse = await fetch('get_history_data.php?hours=6');
                        const newHistoryData = await historyResponse.json();

                        // Обновляем глобальные данные истории
                        if (typeof updateHistoryData === 'function') {
                            updateHistoryData(newHistoryData);
                        }

                        updateHistoryChart(newHistoryData);
                    } 
                    // Если данные изменились - обновляем всё
                    else {
                        const newData = await dataResponse.json();
                        const historyResponse = await fetch('get_history_data.php?hours=6');
                        const newHistoryData = await historyResponse.json();

                        // Обновляем глобальные данные истории
                        if (typeof updateHistoryData === 'function') {
                            updateHistoryData(newHistoryData);
                        }
                        
                        updateCategoriesTable(newData.categories);
                        updateCategoryChart(newData.categories);
                        updateHistoryChart(newHistoryData);
                        updateSummaryTable(newData.categories);
                        
                        // Обновляем lastKnownUpdate
                        lastKnownUpdate = newData.last_update;
                        document.querySelector('.last-update').textContent = 
                            `Последнее обновление: ${newData.last_update}`;
                    }
                    
                } catch (error) {
                    console.error('Ошибка:', error);
                    // Обработка ошибок...
                } finally {
                    setTimeout(() => indicator.classList.remove('visible'), 1000);
                }
            }

            // Функция для обновления графика категорий
            function updateCategoryChart(categories) {
                if (!categoryChartInstance) return;
                
                categoryChartInstance.data.labels = categories.map(c => c.category);
                categoryChartInstance.data.datasets[0].data = categories.map(c => c.up_speed);
                categoryChartInstance.update();
            }
            // Функция для обновления графика инстансов
            function updateHistoryChart(newHistoryData) {
                if (!historyChartInstance) return;
                
                // 1. Получаем уникальные timestamp'ы и сортируем их
                const timestamps = [...new Set(newHistoryData.map(item => item.timestamp))].sort();
                
                // 2. Обновляем метки графика (ось X)
                historyChartInstance.data.labels = timestamps.map(time => new Date(time).toLocaleTimeString());
                
                // 3. Обновляем данные для каждого датасета
                historyChartInstance.data.datasets.forEach(dataset => {
                    const instanceName = dataset.label.split(' ')[0];
                    const isDownload = dataset.label.includes('Download');
                    
                    dataset.data = timestamps.map(time => {
                        const record = newHistoryData.find(item => 
                            item.instance_name === instanceName && 
                            item.timestamp === time
                        );
                        return record ? (isDownload ? record.dl_speed : record.up_speed) : 0;
                    });
                });
                
                // 4. Обновляем график
                historyChartInstance.update();
            }

            // Запускаем проверку каждые 30 секунд
            setInterval(refreshData, 30000);

            // Инициализируем первый раз
            refreshData();
            
            // Ручное обновление
            $('#refresh-btn').click(function() {
                isFirstLoad = false;
                refreshData();
            });
            
            // Автообновление
            autoRefreshCheckbox.change(function() {
                const isChecked = this.checked;
                
                localStorage.setItem('autoRefreshEnabled', isChecked);
                
                if (isChecked) {
                    if (!isFirstLoad) {
                        refreshInterval = setInterval(refreshData, 300000); // 5 минут
                    }
                } else {
                    clearInterval(refreshInterval);
                }
            });
            
            // Инициализация автообновления
            if (autoRefreshCheckbox.is(':checked')) {
                refreshInterval = setInterval(refreshData, 300000);
            }
            
            $(window).on('load', function() {
                isFirstLoad = false;
            });

            // анимация обновления
            function showUpdateIndicator() {
                const indicator = document.createElement('div');
                indicator.className = 'update-indicator';
                document.body.appendChild(indicator);
                setTimeout(() => indicator.remove(), 1000);
            }
            
            // Инициализация сортировки таблицы
            $('#categoriesTable').on('click', '.sortable', function() {
                const table = this.closest('table');
                const column = this.dataset.column;
                const currentDirection = this.querySelector('.sort-arrow').classList.contains('asc') ? 'desc' : 'asc';
                
                sortTable(table, column, currentDirection);
                
                localStorage.setItem('categoriesTableSort', JSON.stringify({
                    column: column,
                    direction: currentDirection
                }));
            });
            
            // Восстановление состояния сортировки
            const savedSort = localStorage.getItem('categoriesTableSort');
            if (savedSort) {
                try {
                    const { column, direction } = JSON.parse(savedSort);
                    const table = document.getElementById('categoriesTable');
                    sortTable(table, column, direction);
                } catch (e) {
                    console.error('Error loading sort state:', e);
                }
            }
            
            // Инициализация графиков
            initHistoryChart(historyData); // Теперь принимает данные
            initCategoryChart(currentData.categories);
        });
    </script>
</body>
</html>