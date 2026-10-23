<?php
require 'config.php';
requireAuth();

$user = currentUser();
$isAdmin = hasRole('admin');
$isManager = hasRole('manager');

// Гард для GET-параметров: ?action[]=x больше не ломает страницу (500)
$g = fn(string $key): string => isset($_GET[$key]) && is_string($_GET[$key]) ? $_GET[$key] : '';
$actionFilter   = $g('action');
$userFilter     = $g('user_id');
$entityFilter   = $g('entity_type');
$dateFromFilter = $g('date_from');
$dateToFilter   = $g('date_to');

// Параметры фильтрации + пагинация
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = max(10, min(999999, (int)($_GET['per_page'] ?? 50)));
$offset = ($page - 1) * $perPage;

$where = [];
$params = [];

if ($actionFilter !== '') {
    $where[] = 'l.action = ?';
    $params[] = $actionFilter;
}
if ($userFilter !== '' && (int)$userFilter > 0) {
    $where[] = 'l.user_id = ?';
    $params[] = (int)$userFilter;
}
if ($entityFilter !== '') {
    $where[] = 'l.entity_type = ?';
    $params[] = $entityFilter;
}
if ($dateFromFilter !== '') {
    $where[] = 'l.created_at >= ?';
    $params[] = $dateFromFilter . ' 00:00:00';
}
if ($dateToFilter !== '') {
    $where[] = 'l.created_at <= ?';
    $params[] = $dateToFilter . ' 23:59:59';
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM logs l $whereSql");
$countStmt->execute($params);
$totalRows = $countStmt->fetchColumn();
$totalPages = ceil($totalRows / $perPage);

$stmt = $pdo->prepare("SELECT l.* FROM logs l $whereSql ORDER BY l.created_at DESC LIMIT $perPage OFFSET $offset");
$stmt->execute($params);
$logs = $stmt->fetchAll();

$actions = $pdo->query("SELECT DISTINCT action FROM logs ORDER BY action")->fetchAll(PDO::FETCH_COLUMN);
$entityTypes = $pdo->query("SELECT DISTINCT entity_type FROM logs WHERE entity_type IS NOT NULL ORDER BY entity_type")->fetchAll(PDO::FETCH_COLUMN);
$users = $pdo->query("SELECT id, username, full_name FROM users ORDER BY username")->fetchAll();

$actionIcons = [
    'login'           => '🔓', 'logout'          => '🔒',
    'create'          => '🟢', 'update'          => '🟡',
    'delete'          => '🔴', 'password_change' => '🔑',
    'toggle_active'   => '🔄', 'import'          => '📥',
    'export'          => '📤',
];

$entityLabels = [
    'claim'  => 'Претензия',
    'object' => 'Объект',
    'user'   => 'Пользователь',
];

$queryParams = $_GET;
unset($queryParams['page']);

// ===== Переменные для шаблона =====
$pageTitle = 'Журнал действий — Реестр претензий';
$currentPage = 'logs';
$showUsersLink = false;
$isAdmin = $isAdmin;
$extraHead = '';

ob_start();
?>
            <div class="mt-auto">
                <hr class="sidebar-divider">
                <a href="logout.php" class="btn btn-outline-danger w-100 btn-sm"><i class="bi bi-box-arrow-right"></i> Выйти</a>
            </div>
<?php
$sidebarContent = ob_get_clean();

require 'templates/header.php';
?>
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h3 class="fw-bold mb-0"><i class="bi bi-journal-text"></i> Журнал действий</h3>
                <div>
                    <small class="text-muted me-2">Всего записей: <strong><?= number_format($totalRows, 0, '.', ' ') ?></strong></small>
                    <a href="logs.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-clockwise"></i> Сбросить фильтр</a>
                </div>
            </div>

            <div class="card shadow-sm border-0 mb-4">
                <div class="card-body p-3">
                    <form method="GET" class="row g-2 align-items-end">
                        <div class="col-md-2">
                            <label class="form-label small">Действие</label>
                            <select name="action" class="form-select form-select-sm">
                                <option value="">— Все —</option>
                                <?php foreach ($actions as $a): ?>
                                    <option value="<?= $a ?>" <?= $actionFilter === $a ? 'selected' : '' ?>>
                                        <?= ($actionIcons[$a] ?? '•') . ' ' . $a ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small">Пользователь</label>
                            <select name="user_id" class="form-select form-select-sm">
                                <option value="">— Все —</option>
                                <?php foreach ($users as $u): ?>
                                    <option value="<?= $u['id'] ?>" <?= $userFilter == $u['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($u['username']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small">Тип записи</label>
                            <select name="entity_type" class="form-select form-select-sm">
                                <option value="">— Все —</option>
                                <?php foreach ($entityTypes as $et): ?>
                                    <option value="<?= $et ?>" <?= $entityFilter === $et ? 'selected' : '' ?>>
                                        <?= $entityLabels[$et] ?? $et ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small">Дата с</label>
                            <input type="date" name="date_from" class="form-control form-control-sm" value="<?= $dateFromFilter ?>">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small">Дата по</label>
                            <input type="date" name="date_to" class="form-control form-control-sm" value="<?= $dateToFilter ?>">
                        </div>
                        <div class="col-md-2">
                            <button type="submit" class="btn btn-primary btn-sm w-100"><i class="bi bi-search"></i> Поиск</button>
                        </div>
                    </form>
                </div>
            </div>

            <div class="card shadow-sm border-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0 table-sm">
                        <thead class="table-dark">
                            <tr>
                                <th style="width:60px;">ID</th>
                                <th style="width:160px;">Дата и время</th>
                                <th style="width:130px;">Пользователь</th>
                                <th style="width:100px;">Действие</th>
                                <th style="width:120px;">Объект</th>
                                <th>Детали</th>
                                <th style="width:140px;">IP-адрес</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($logs) === 0): ?>
                                <tr><td colspan="7" class="text-center py-4 text-muted">
                                    <i class="bi bi-journal-text fs-2 d-block mb-2"></i>
                                    Нет записей
                                </td></tr>
                            <?php else: foreach ($logs as $log): ?>
                                <tr>
                                    <td class="fw-bold small"><?= $log['id'] ?></td>
                                    <td class="small">
                                        <i class="bi bi-clock text-muted"></i>
                                        <?= date('d.m.Y H:i', strtotime($log['created_at'])) ?>
                                    </td>
                                    <td>
                                        <small><i class="bi bi-person-circle"></i> <strong><?= htmlspecialchars($log['full_name'] ?: $log['username']) ?></strong></small>
                                    </td>
                                    <td>
                                        <?php
                                        $icon = $actionIcons[$log['action']] ?? '•';
                                        $color = match($log['action']) {
                                            'create', 'login' => 'text-success',
                                            'delete' => 'text-danger',
                                            'update', 'password_change', 'toggle_active' => 'text-warning',
                                            'logout' => 'text-secondary',
                                            default => ''
                                        };
                                        ?>
                                        <span class="badge bg-light text-dark border">
                                            <span class="<?= $color ?>"><?= $icon ?></span>
                                            <?= htmlspecialchars($log['action']) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($log['entity_type']): ?>
                                            <span class="badge bg-info bg-opacity-10 text-dark border">
                                                <?= $entityLabels[$log['entity_type']] ?? $log['entity_type'] ?>
                                                <?php if ($log['entity_id']): ?>#<?= $log['entity_id'] ?><?php endif; ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="text-muted small">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="small">
                                        <?= htmlspecialchars(mb_substr($log['details'] ?? '', 0, 200)) ?>
                                        <?php if (mb_strlen($log['details'] ?? '') > 200): ?>
                                            <span class="text-muted">...</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="small text-muted"><code><?= htmlspecialchars($log['ip_address']) ?></code></td>
                                </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($totalPages > 1 || $totalRows > 50): ?>
                <div class="card-footer bg-white d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <div class="d-flex align-items-center gap-2">
                        <small class="text-muted">Страница <?= $page ?> из <?= $totalPages ?></small>
                        <form method="GET" class="d-inline-block">
                            <?php foreach ($_GET as $k => $v): ?>
                                <?php if (!is_string($v) || ($k === 'per_page' || $k === 'page')) continue; ?>
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
            </div>

            <div class="row g-3 mt-2">
                <div class="col-md-3">
                    <div class="card shadow-sm border-0 bg-success bg-opacity-10">
                        <div class="card-body p-3 text-center">
                            <div class="fs-4 fw-bold text-success"><?= $pdo->query("SELECT COUNT(*) FROM logs WHERE action='create'")->fetchColumn() ?></div>
                            <small class="text-muted">🟢 Создано</small>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card shadow-sm border-0 bg-warning bg-opacity-10">
                        <div class="card-body p-3 text-center">
                            <div class="fs-4 fw-bold text-warning"><?= $pdo->query("SELECT COUNT(*) FROM logs WHERE action='update'")->fetchColumn() ?></div>
                            <small class="text-muted">🟡 Изменено</small>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card shadow-sm border-0 bg-danger bg-opacity-10">
                        <div class="card-body p-3 text-center">
                            <div class="fs-4 fw-bold text-danger"><?= $pdo->query("SELECT COUNT(*) FROM logs WHERE action='delete'")->fetchColumn() ?></div>
                            <small class="text-muted">🔴 Удалено</small>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card shadow-sm border-0 bg-info bg-opacity-10">
                        <div class="card-body p-3 text-center">
                            <div class="fs-4 fw-bold text-info"><?= $pdo->query("SELECT COUNT(*) FROM logs WHERE action='login'")->fetchColumn() ?></div>
                            <small class="text-muted">🔓 Входов</small>
                        </div>
                    </div>
                </div>
            </div>
<?php
require 'templates/footer.php';