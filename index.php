<?php
require 'config.php';
requireAuth();

$g = fn(string $key): string => isset($_GET[$key]) && is_string($_GET[$key]) ? $_GET[$key] : '';
 $objectFilter    = $g('object_id');
 $searchQuery     = $g('search');
 $directionFilter = $g('direction');
 $statusFilter    = $g('status');
 $colorFilter     = $g('color');

$where = [];
$params = [];

if ($objectFilter) {
    $where[] = 'c.object_id = ?';
    $params[] = (int)$objectFilter;
}
if ($searchQuery) {
    $where[] = '(c.applicant LIKE ? OR c.subject LIKE ? OR c.apartment_number LIKE ?
                 OR o.name LIKE ? OR c.received_from LIKE ? OR c.current_status LIKE ?
                 OR c.response_direction LIKE ? OR c.direction LIKE ?)';
    $s = '%' . $searchQuery . '%';
    $params = array_merge($params, [$s, $s, $s, $s, $s, $s, $s, $s]);
}
if ($directionFilter) {
    $where[] = 'c.direction = ?';
    $params[] = $directionFilter;
}
if ($statusFilter) {
    $where[] = 'c.current_status LIKE ?';
    $params[] = '%' . $statusFilter . '%';
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// Цвет срока — та же логика, что getClaimStatusColor(), но силами MySQL:
// это позволяет фильтровать, считать статистику и пагинировать на стороне БД
$statusColorSql = "CASE
    WHEN c.response_date IS NOT NULL AND c.response_date <> '' THEN 'green'
    WHEN COALESCE(c.received_date, '') = '' THEN 'green'
    WHEN DATEDIFF(CURDATE(), c.received_date) < 10 THEN 'green'
    WHEN DATEDIFF(CURDATE(), c.received_date) < 20 THEN 'yellow'
    WHEN DATEDIFF(CURDATE(), c.received_date) < 30 THEN 'red'
    ELSE 'purple'
END";

if (in_array($colorFilter, ['green', 'yellow', 'red', 'purple'], true)) {
    $where[] = "$statusColorSql = ?";
    $params[] = $colorFilter;
    $whereSql = 'WHERE ' . implode(' AND ', $where);
}

$fromSql = "FROM claims c
    LEFT JOIN objects o ON c.object_id = o.id
    LEFT JOIN users u ON c.created_by = u.id
    LEFT JOIN companies comp ON c.recipient_id = comp.id";

// Счётчик и статистика по цветам — один агрегатный запрос вместо выборки всех строк
$statsStmt = $pdo->prepare("SELECT COUNT(*) AS total,
        COALESCE(SUM($statusColorSql = 'green'), 0)  AS green,
        COALESCE(SUM($statusColorSql = 'yellow'), 0) AS yellow,
        COALESCE(SUM($statusColorSql = 'red'), 0)    AS red,
        COALESCE(SUM($statusColorSql = 'purple'), 0) AS purple
    $fromSql $whereSql");
$statsStmt->execute($params);
$stats = $statsStmt->fetch();

$total     = (int)($stats['total']  ?? 0);
$greenCnt  = (int)($stats['green']  ?? 0);
$yellowCnt = (int)($stats['yellow'] ?? 0);
$redCnt    = (int)($stats['red']    ?? 0);
$purpleCnt = (int)($stats['purple'] ?? 0);

// ===== Пагинация на стороне SQL (LIMIT/OFFSET вместо array_slice) =====
$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = max(10, min(999999, (int)($_GET['per_page'] ?? 50)));
$offset  = ($page - 1) * $perPage;
$totalPages = max(1, (int)ceil($total / $perPage));

$pageStmt = $pdo->prepare("SELECT c.*, o.name as object_name, u.full_name as created_by_name, comp.name as recipient_name
    $fromSql $whereSql ORDER BY c.created_at DESC LIMIT $perPage OFFSET $offset");
$pageStmt->execute($params);
$claimsPage = $pageStmt->fetchAll();

$queryParams = $_GET;
unset($queryParams['page']);

$user = currentUser();
$objects = $pdo->query("SELECT * FROM objects ORDER BY name")->fetchAll();

// ===== Переменные для шаблона =====
$pageTitle = 'Реестр претензий';
$currentPage = 'index';
$showUsersLink = true;
$isAdmin = false;

$extraHead = <<<'HEAD'
<style>
.stat-mini {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 0.85rem;
    font-weight: 600;
}
.stat-mini.green  { background: #e8f5e9; color: #2e7d32; }
.stat-mini.yellow { background: #fff8e1; color: #e65100; }
.stat-mini.red    { background: #ffebee; color: #c62828; }
.stat-mini.purple { background: #f3e5f5; color: #6a1b9a; }
.row-green  { background-color: #e8f5e9 !important; }
.row-yellow { background-color: #fff8e1 !important; }
.row-red    { background-color: #ffebee !important; }
.row-purple { background-color: #f3e5f5 !important; }
/* .badge-green/yellow/red/purple теперь глобально в assets/style.css —
   дубли здесь убраны, чтобы не переопределять общий стиль бейджей */
.legend-dot { display: inline-block; width: 12px; height: 12px; border-radius: 50%; margin-right: 4px; vertical-align: middle; }
</style>
HEAD;

// Sidebar со всеми фильтрами — собираем в буфер
ob_start();
?>
            <h6 class="sidebar-label"><i class="bi bi-funnel"></i> По статусу срока</h6>
            <div class="nav flex-column mb-3">
                <a href="?color=green" class="nav-link <?= $colorFilter == 'green' ? 'active' : '' ?>">
                    <span class="legend-dot" style="background:#4caf50;"></span> ✅ В срок
                </a>
                <a href="?color=yellow" class="nav-link <?= $colorFilter == 'yellow' ? 'active' : '' ?>">
                    <span class="legend-dot" style="background:#ff9800;"></span> ⚠️ Внимание
                </a>
                <a href="?color=red" class="nav-link <?= $colorFilter == 'red' ? 'active' : '' ?>">
                    <span class="legend-dot" style="background:#f44336;"></span> 🔴 Просрочка
                </a>
                <a href="?color=purple" class="nav-link <?= $colorFilter == 'purple' ? 'active' : '' ?>">
                    <span class="legend-dot" style="background:#9c27b0;"></span> 🟣 Просрочен
                </a>
                <a href="index.php" class="nav-link small text-muted mt-1">✕ Сбросить фильтр</a>
            </div>

            <h6 class="sidebar-label"><i class="bi bi-building"></i> Объекты</h6>
            <div class="nav flex-column mb-3" style="max-height:200px; overflow-y:auto;">
                <a href="index.php" class="nav-link <?= !$objectFilter ? 'active' : '' ?>">Все объекты</a>
                <?php foreach ($objects as $obj): ?>
                    <a href="?object_id=<?= $obj['id'] ?>"
                       class="nav-link small <?= $objectFilter == $obj['id'] ? 'active' : '' ?>">
                        <?= htmlspecialchars($obj['name']) ?>
                    </a>
                <?php endforeach; ?>
            </div>

            <div class="mt-3">
                <?php if (hasRole('admin', 'manager')): ?>
                    <a href="import.php" class="btn btn-outline-light w-100 mb-2 btn-sm"><i class="bi bi-upload"></i> Импорт</a>
                <?php endif; ?>
                <a href="export.php<?= $objectFilter ? '?object_id='.$objectFilter : '' ?>" class="btn btn-outline-light w-100 mb-2 btn-sm"><i class="bi bi-download"></i> Экспорт</a>
            </div>
<?php
$sidebarContent = ob_get_clean();

require 'templates/header.php';
?>
            <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
                <div>
                    <h3 class="fw-bold mb-0"><i class="bi bi-list-ul"></i> Реестр претензий</h3>
                    <small class="text-muted">
                        <i class="bi bi-calendar3"></i> <?= date('d.m.Y') ?>
                        | Всего: <strong><?= $total ?></strong>
                    </small>
                </div>
                <div class="d-flex align-items-center gap-2">
                    <span class="stat-mini green"><span class="legend-dot" style="background:#4caf50;"></span> <?= $greenCnt ?></span>
                    <span class="stat-mini yellow"><span class="legend-dot" style="background:#ff9800;"></span> <?= $yellowCnt ?></span>
                    <span class="stat-mini red"><span class="legend-dot" style="background:#f44336;"></span> <?= $redCnt ?></span>
                    <span class="stat-mini purple"><span class="legend-dot" style="background:#9c27b0;"></span> <?= $purpleCnt ?></span>
                    <?php if (hasRole('admin', 'manager')): ?>
                        <a href="add.php" class="btn btn-success"><i class="bi bi-plus-lg"></i> Добавить претензию</a>
                    <?php endif; ?>
                </div>
            </div>

            <?php if (isset($_GET['added'])): ?>
                <div class="alert alert-success alert-dismissible fade show">✅ Претензия добавлена!<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
            <?php elseif (isset($_GET['deleted'])): ?>
                <div class="alert alert-warning alert-dismissible fade show">🗑️ Претензия удалена.<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
            <?php elseif (isset($_GET['updated'])): ?>
                <div class="alert alert-info alert-dismissible fade show">📝 Изменения сохранены.<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
            <?php endif; ?>

            <div class="card shadow-sm border-0 mb-3">
                <div class="card-body py-3">
                    <form method="GET" class="row g-2 align-items-end">
                        <?php if ($colorFilter): ?>
                            <input type="hidden" name="color" value="<?= $colorFilter ?>">
                        <?php endif; ?>
                        <div class="col-md-5">
                            <input type="text" name="search" class="form-control"
                                   placeholder="🔍 Поиск по всем полям: заявитель, предмет, объект, статус, источник..."
                                   value="<?= htmlspecialchars($searchQuery) ?>">
                        </div>
                        <div class="col-md-2">
                            <select name="direction" class="form-select">
                                <option value="">Все направления</option>
                                <option value="incoming" <?= $directionFilter == 'incoming' ? 'selected' : '' ?>>📥 Входящие</option>
                                <option value="outgoing" <?= $directionFilter == 'outgoing' ? 'selected' : '' ?>>📤 Исходящие</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <input type="text" name="status" class="form-control" placeholder="📋 Статус..."
                                   value="<?= htmlspecialchars($statusFilter) ?>">
                        </div>
                        <div class="col-md-1">
                            <button type="submit" class="btn btn-primary w-100"><i class="bi bi-search"></i></button>
                        </div>
                        <div class="col-md-1">
                            <a href="index.php" class="btn btn-outline-secondary w-100"><i class="bi bi-x-circle"></i></a>
                        </div>
                    </form>
                </div>
            </div>

            <div class="card shadow-sm border-0">
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-dark">
                                <tr>
                                    <th style="width:40px;">#</th>
                                    <th>Объект</th>
                                    <th>Заявитель</th>
                                    <th>№ кв.</th>
                                    <th>Напр.</th>
                                    <th>Получатель</th>
                                    <th>Предмет претензии</th>
                                    <th>Дата пост.</th>
                                    <th>Дата ответа</th>
                                    <th title="Дни с даты поступления; для закрытых претензий счётчик остановлен на дате ответа">Дней</th>
                                    <th>Статус срока</th>
                                    <th>Файлы</th>
                                    <th style="width:90px;">Действия</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (count($claimsPage) === 0): ?>
                                    <tr><td colspan="13" class="text-center py-5 text-muted">
                                        <i class="bi bi-inbox fs-1 d-block mb-2"></i>
                                        Претензий не найдено
                                        <br><a href="add.php" class="btn btn-sm btn-success mt-2">➕ Добавить первую претензию</a>
                                    </td></tr>
                                <?php else: foreach ($claimsPage as $i => $c):
                                    $color = getClaimStatusColor($c);
                                    $days  = getDaysPassed($c);
                                ?>
                                    <tr class="row-<?= $color ?>">
                                        <td class="fw-bold"><?= $offset + $i + 1 ?></td>
                                        <td><span class="badge bg-info"><?= htmlspecialchars($c['object_name']) ?></span></td>
                                        <td><strong><?= htmlspecialchars($c['applicant']) ?></strong></td>
                                        <td><?= htmlspecialchars($c['apartment_number'] ?? '—') ?></td>
                                        <td>
                                            <?php if ($c['direction'] === 'incoming'): ?>
                                                <span class="badge bg-warning text-dark">📥</span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary">📤</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= htmlspecialchars($c['recipient_name'] ?? '—') ?></td>
                                        <td class="text-truncate" style="max-width:200px;" title="<?= htmlspecialchars($c['subject']) ?>">
                                            <?= htmlspecialchars(mb_substr($c['subject'], 0, 60)) ?>
                                            <?= mb_strlen($c['subject']) > 60 ? '...' : '' ?>
                                        </td>
                                        <td><?= $c['received_date'] ? date('d.m.Y', strtotime($c['received_date'])) : '—' ?></td>
                                        <td><?= $c['response_date'] ? date('d.m.Y', strtotime($c['response_date'])) : '—' ?></td>
                                        <td>
                                            <?php if ($c['response_date']): ?>
                                                <span class="fw-bold text-muted" title="Ответ дан <?= date('d.m.Y', strtotime($c['response_date'])) ?> — счётчик остановлен"><?= $days ?></span>
                                            <?php else: ?>
                                                <span class="fw-bold" title="Дней с даты поступления"><?= $days ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="badge badge-<?= $color ?>"><?= getStatusLabel($color) ?></span>
                                            <br><small class="text-muted"><?= htmlspecialchars($c['current_status'] ?? '—') ?></small>
                                        </td>
                                        <td>
                                            <?php if ($c['claim_file']): ?>
                                                <a href="<?= uploadLink($c['claim_file']) ?>" target="_blank" class="btn btn-sm btn-outline-danger" title="Претензия"><i class="bi bi-filetype-pdf"></i></a>
                                            <?php endif; ?>
                                            <?php if ($c['response_file']): ?>
                                                <a href="<?= uploadLink($c['response_file']) ?>" target="_blank" class="btn btn-sm btn-outline-success" title="Ответ"><i class="bi bi-reply-all"></i></a>
                                            <?php endif; ?>
                                            <?php
                                            $addFiles = $c['additional_files'] ? json_decode($c['additional_files'], true) : [];
                                            if (!empty($addFiles)): ?>
                                                <span class="badge bg-secondary" title="Доп. файлы: <?= count($addFiles) ?>">+<?= count($addFiles) ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="btn-group btn-group-sm">
                                                <a href="view.php?id=<?= $c['id'] ?>" class="btn btn-outline-primary" title="Просмотр"><i class="bi bi-eye"></i></a>
                                                <?php if (hasRole('admin', 'manager')): ?>
                                                    <a href="edit.php?id=<?= $c['id'] ?>" class="btn btn-outline-warning" title="Редактировать"><i class="bi bi-pencil"></i></a>
                                                <?php endif; ?>
                                                <?php if (hasRole('admin', 'manager')): ?>
                                                    <a href="delete.php?id=<?= $c['id'] ?>" class="btn btn-outline-danger" onclick="return confirm('Удалить претензию №<?= $c['id'] ?>?')" title="Удалить"><i class="bi bi-trash"></i></a>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <?php if ($totalPages > 1 || $total > 50): ?>
                <div class="card-footer bg-white d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <div class="d-flex align-items-center gap-2">
                        <small class="text-muted">
                            Страница <?= $page ?> из <?= $totalPages ?>
                            (всего: <?= $total ?>)
                        </small>
                        <form method="GET" class="d-inline-block">
                                <?php foreach ($_GET as $k => $v): ?>
                                    <?php if (!is_string($v) || $k === 'per_page' || $k === 'page') continue; ?>
                                    <input type="hidden" name="<?= htmlspecialchars($k) ?>" value="<?= htmlspecialchars($v) ?>">
                                <?php endforeach; ?>
                            <div class="input-group input-group-sm">
                                <span class="input-group-text">Показывать</span>
                                <select name="per_page" class="form-select" onchange="this.form.submit()" style="width:auto; min-width:70px;">
                                    <option value="50" <?= $perPage == 50 ? 'selected' : '' ?>>50</option>
                                    <option value="100" <?= $perPage == 100 ? 'selected' : '' ?>>100</option>
                                    <option value="200" <?= $perPage == 200 ? 'selected' : '' ?>>200</option>
                                    <option value="999999" <?= $perPage >= 999999 ? 'selected' : '' ?>>Все</option>
                                </select>
                            </div>
                        </form>
                    </div>
                    <?php if ($totalPages > 1): ?>
                    <nav>
                        <ul class="pagination pagination-sm mb-0">
                            <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                                <a class="page-link" href="?<?= http_build_query(array_merge($queryParams, ['page' => $page - 1, 'per_page' => $perPage])) ?>">«</a>
                            </li>
                            <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                                <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                                    <a class="page-link" href="?<?= http_build_query(array_merge($queryParams, ['page' => $i, 'per_page' => $perPage])) ?>"><?= $i ?></a>
                                </li>
                            <?php endfor; ?>
                            <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                                <a class="page-link" href="?<?= http_build_query(array_merge($queryParams, ['page' => $page + 1, 'per_page' => $perPage])) ?>">»</a>
                            </li>
                        </ul>
                    </nav>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <?php if ($total > 0): ?>
                <div class="card-footer bg-white text-muted small d-flex justify-content-between border-top">
                    <div>
                        <span class="legend-dot" style="background:#4caf50;"></span> В срок
                        <span class="legend-dot" style="background:#ff9800;"></span> 10–19 дн.
                        <span class="legend-dot" style="background:#f44336;"></span> 20–29 дн.
                        <span class="legend-dot" style="background:#9c27b0;"></span> 30+ дн.
                        <span class="legend-dot" style="background:#adb5bd;"></span> серым — ответ дан (счётчик остановлен)
                    </div>
                    <div>Всего: <strong><?= $total ?></strong> записей</div>
                </div>
                <?php endif; ?>
            </div>
<?php
require 'templates/footer.php';