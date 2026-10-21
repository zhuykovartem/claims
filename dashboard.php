<?php
require 'config.php';
requireAuth();

$allClaims = $pdo->query("SELECT c.*, o.name as object_name
    FROM claims c LEFT JOIN objects o ON c.object_id = o.id ORDER BY c.created_at DESC")->fetchAll();

$total = count($allClaims);
$greenCnt = $yellowCnt = $redCnt = $purpleCnt = 0;
$withResp = $withoutResp = 0;
$incomingCnt = $outgoingCnt = 0;
$statuses = [];
$objectsStat = [];

foreach ($allClaims as $c) {
    $color = getClaimStatusColor($c);
    switch ($color) {
        case 'green':  $greenCnt++;  break;
        case 'yellow': $yellowCnt++; break;
        case 'red':    $redCnt++;    break;
        case 'purple': $purpleCnt++; break;
    }
    if ($c['response_file']) $withResp++; else $withoutResp++;
    if ($c['direction'] === 'incoming') $incomingCnt++; else $outgoingCnt++;
    $st = $c['current_status'] ?: 'Без статуса';
    $statuses[$st] = ($statuses[$st] ?? 0) + 1;
    $objName = $c['object_name'] ?: 'Без объекта';
    $objectsStat[$objName] = ($objectsStat[$objName] ?? 0) + 1;
}

$stmtMonths = $pdo->query("SELECT DATE_FORMAT(created_at, '%Y-%m') as month, COUNT(*) as cnt
    FROM claims GROUP BY month ORDER BY month ASC LIMIT 12");
$monthsData = $stmtMonths->fetchAll();
$monthsLabels = $monthsCounts = [];
foreach ($monthsData as $m) {
    $monthsLabels[] = $m['month'];
    $monthsCounts[] = (int)$m['cnt'];
}

$user = currentUser();

// ===== Переменные для шаблона =====
$pageTitle = 'Дашборд — Реестр претензий';
$currentPage = 'dashboard';
$showUsersLink = true;
$isAdmin = false;
$extraHead = <<<HEAD
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<style>
.stat-card { border-radius: 12px; transition: transform 0.15s; }
.stat-card:hover { transform: translateY(-3px); box-shadow: 0 8px 25px rgba(0,0,0,0.1); }
.chart-container { position: relative; height: 250px; width: 100%; }
.big-number { font-size: 2.5rem; font-weight: 700; line-height: 1; }
</style>
HEAD;
$sidebarContent = '';

require 'templates/header.php';
?>
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h3 class="fw-bold mb-0"><i class="bi bi-speedometer2"></i> Дашборд и аналитика</h3>
                <a href="add.php" class="btn btn-success"><i class="bi bi-plus-lg"></i> Добавить претензию</a>
            </div>

            <div class="row g-3 mb-4">
                <div class="col-md-3">
                    <div class="card stat-card border-0 shadow-sm h-100">
                        <div class="card-body text-center p-3">
                            <div class="big-number text-primary"><?= $total ?></div>
                            <p class="text-muted mb-0">Всего претензий</p>
                            <small>📥 <?= $incomingCnt ?> вх. / 📤 <?= $outgoingCnt ?> исх.</small>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card stat-card border-0 shadow-sm h-100" style="background: #e8f5e9;">
                        <div class="card-body text-center p-3">
                            <div class="big-number" style="color:#2e7d32;"><?= $greenCnt ?></div>
                            <p class="mb-0">✅ В срок (0–9 дн.)</p>
                            <small>📎 с ответом: <?= $withResp ?></small>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card stat-card border-0 shadow-sm h-100" style="background: #fff8e1;">
                        <div class="card-body text-center p-3">
                            <div class="big-number" style="color:#e65100;"><?= $yellowCnt ?></div>
                            <p class="mb-0">⚠️ Внимание (10–19 дн.)</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card stat-card border-0 shadow-sm h-100" style="background: #ffebee;">
                        <div class="card-body text-center p-3">
                            <div class="big-number" style="color:#c62828;"><?= $redCnt + $purpleCnt ?></div>
                            <p class="mb-0">🔴 Просрочка 🟣 Просрочен</p>
                            <small>🔴 <?= $redCnt ?> | 🟣 <?= $purpleCnt ?></small>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row g-3 mb-4">
                <div class="col-md-4">
                    <div class="card shadow-sm border-0 h-100">
                        <div class="card-body">
                            <h6 class="fw-bold"><i class="bi bi-pie-chart"></i> Распределение по срокам</h6>
                            <div class="chart-container"><canvas id="pieChart"></canvas></div>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card shadow-sm border-0 h-100">
                        <div class="card-body">
                            <h6 class="fw-bold"><i class="bi bi-bar-chart"></i> По объектам</h6>
                            <div class="chart-container"><canvas id="barChart"></canvas></div>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card shadow-sm border-0 h-100">
                        <div class="card-body">
                            <h6 class="fw-bold"><i class="bi bi-graph-up"></i> Динамика по месяцам</h6>
                            <div class="chart-container"><canvas id="lineChart"></canvas></div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row g-3 mb-4">
                <div class="col-md-6">
                    <div class="card shadow-sm border-0">
                        <div class="card-body">
                            <h6 class="fw-bold"><i class="bi bi-tags"></i> Статусы претензий</h6>
                            <div class="chart-container"><canvas id="statusesChart"></canvas></div>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="card shadow-sm border-0">
                        <div class="card-body">
                            <h6 class="fw-bold"><i class="bi bi-arrow-left-right"></i> Входящие / Исходящие</h6>
                            <div class="chart-container"><canvas id="directionChart"></canvas></div>
                        </div>
                    </div>
                </div>
            </div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const pieCtx = document.getElementById('pieChart');
    if (pieCtx) new Chart(pieCtx, {
        type: 'doughnut',
        data: {
            labels: ['✅ В срок (0–9 дн.)', '⚠️ Внимание (10–19 дн.)', '🔴 Просрочка (20–29 дн.)', '🟣 Просрочен (30+)'],
            datasets: [{ data: [<?= $greenCnt ?>, <?= $yellowCnt ?>, <?= $redCnt ?>, <?= $purpleCnt ?>], backgroundColor: ['#4caf50','#ff9800','#f44336','#9c27b0'], borderWidth: 2, borderColor: '#fff' }]
        },
        options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom', labels: { boxWidth: 12, font: { size: 10 } } } } }
    });

    const barCtx = document.getElementById('barChart');
    if (barCtx) new Chart(barCtx, {
        type: 'bar',
        data: {
            labels: <?= json_encode(array_keys($objectsStat)) ?>,
            datasets: [{ label: 'Претензий', data: <?= json_encode(array_values($objectsStat)) ?>, backgroundColor: '#2196f3', borderRadius: 4 }]
        },
        options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { stepSize: 1 } }, x: { ticks: { font: { size: 9 } } } } }
    });

    const lineCtx = document.getElementById('lineChart');
    if (lineCtx) new Chart(lineCtx, {
        type: 'line',
        data: {
            labels: <?= json_encode($monthsLabels) ?>,
            datasets: [{ label: 'Новых претензий', data: <?= json_encode($monthsCounts) ?>, borderColor: '#4caf50', backgroundColor: 'rgba(76,175,80,0.1)', fill: true, tension: 0.3, pointBackgroundColor: '#4caf50', pointRadius: 4 }]
        },
        options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { stepSize: 1 } } } }
    });

    const statusCtx = document.getElementById('statusesChart');
    if (statusCtx) new Chart(statusCtx, {
        type: 'bar',
        data: {
            labels: <?= json_encode(array_keys($statuses)) ?>,
            datasets: [{ label: 'Количество', data: <?= json_encode(array_values($statuses)) ?>, backgroundColor: ['#4caf50','#ff9800','#f44336','#2196f3','#9c27b0','#607d8b','#e91e63','#795348'], borderRadius: 4 }]
        },
        options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, indexAxis: 'y', scales: { x: { beginAtZero: true, ticks: { stepSize: 1 } } } }
    });

    const dirCtx = document.getElementById('directionChart');
    if (dirCtx) new Chart(dirCtx, {
        type: 'pie',
        data: {
            labels: ['📥 Входящие', '📤 Исходящие'],
            datasets: [{ data: [<?= $incomingCnt ?>, <?= $outgoingCnt ?>], backgroundColor: ['#ff9800', '#607d8b'], borderWidth: 2, borderColor: '#fff' }]
        },
        options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom' } } }
    });
});
</script>

<script>
// Автоматическое скрытие уведомлений через 5 секунд
document.addEventListener('DOMContentLoaded', function() {
    setTimeout(function() {
        var alerts = document.querySelectorAll('.alert-dismissible');
        alerts.forEach(function(alert) {
            var bsAlert = new bootstrap.Alert(alert);
            bsAlert.close();
        });
    }, 5000);
});
</script>
<?php
require 'templates/footer.php';