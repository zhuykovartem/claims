<?php
require 'config.php';
requireAuth();
requireRole('admin', 'manager');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfToken();
}
$csrfToken = getCsrfToken();
$user = currentUser();
$message = '';
$error = '';

// ===== Добавление компании =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_company'])) {
    $name = trim($_POST['company_name'] ?? '');
    if (empty($name)) {
        $error = 'Название компании обязательно';
    } else {
        $stmt = $pdo->prepare("SELECT id FROM companies WHERE name=?");
        $stmt->execute([$name]);
        if ($stmt->fetch()) {
            $error = 'Компания с таким названием уже существует';
        } else {
            $stmt = $pdo->prepare("INSERT INTO companies (name) VALUES (?)");
            $stmt->execute([$name]);
            $newId = $pdo->lastInsertId();
            logAction('create', 'company', $newId, 'Добавлена компания: ' . $name);
            $message = '✅ Компания «' . htmlspecialchars($name) . '» добавлена';
        }
    }
}

// ===== Удаление компании =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_company'])) {
    $id = (int)$_POST['delete_company'];
    $stmt = $pdo->prepare("SELECT name FROM companies WHERE id=?");
    $stmt->execute([$id]);
    $name = $stmt->fetchColumn() ?: 'ID=' . $id;

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM claims WHERE recipient_id=?");
    $stmt->execute([$id]);
    $count = $stmt->fetchColumn();

    if ($count > 0) {
        $error = "❌ Нельзя удалить компанию: она указана получателем в $count претензиях.";
    } else {
        $pdo->prepare("DELETE FROM object_companies WHERE company_id=?")->execute([$id]);
        $pdo->prepare("DELETE FROM companies WHERE id=?")->execute([$id]);
        logAction('delete', 'company', $id, 'Удалена компания: ' . $name);
        $message = '🗑️ Компания удалена';
    }
}

// ===== Добавление объекта строительства =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_object'])) {
    $obj_name    = trim($_POST['object_name'] ?? '');
    $obj_address = trim($_POST['object_address'] ?? '');
    if (empty($obj_name)) {
        $error = 'Название объекта обязательно';
    } else {
        $stmt = $pdo->prepare("SELECT id FROM objects WHERE name=?");
        $stmt->execute([$obj_name]);
        if ($stmt->fetch()) {
            $error = 'Объект с таким названием уже существует';
        } else {
            $stmt = $pdo->prepare("INSERT INTO objects (name, address) VALUES (?, ?)");
            $stmt->execute([$obj_name, $obj_address ?: null]);
            $newId = $pdo->lastInsertId();
            logAction('create', 'object', $newId, 'Добавлен объект: ' . $obj_name);
            $message = '✅ Объект «' . htmlspecialchars($obj_name) . '» добавлен';
        }
    }
}

// ===== Редактирование объекта =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_object'])) {
    $obj_id  = (int)$_POST['edit_object'];
    $obj_name    = trim($_POST['edit_object_name'] ?? '');
    $obj_address = trim($_POST['edit_object_address'] ?? '');

    if ($obj_id <= 0) {
        $error = 'Объект не выбран';
    } elseif ($obj_name === '') {
        $error = 'Название объекта обязательно';
    } else {
        $stmt = $pdo->prepare("SELECT id FROM objects WHERE name=? AND id != ?");
        $stmt->execute([$obj_name, $obj_id]);
        if ($stmt->fetch()) {
            $error = 'Объект с таким названием уже существует';
        } else {
            $stmt = $pdo->prepare("UPDATE objects SET name = ?, address = ? WHERE id = ?");
            $stmt->execute([$obj_name, $obj_address ?: null, $obj_id]);
            logAction('update', 'object', $obj_id, 'Изменён объект: ' . $obj_name);
            $message = '✅ Объект «' . htmlspecialchars($obj_name) . '» обновлён';
        }
    }
}

// ===== Удаление объекта =====
// ВАЖНО: в БД на claims.object_id висит ON DELETE CASCADE — удаление объекта
// с претензиями молча снесло бы все его претензии. Поэтому проверяем счётчик
// в коде и отказываем в удалении.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_object'])) {
    $obj_id = (int)$_POST['delete_object'];
    $stmt = $pdo->prepare("SELECT name FROM objects WHERE id=?");
    $stmt->execute([$obj_id]);
    $obj_name = $stmt->fetchColumn();

    if ($obj_name === false) {
        $error = 'Объект не найден';
    } else {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM claims WHERE object_id=?");
        $stmt->execute([$obj_id]);
        $claimsCount = (int)$stmt->fetchColumn();

        if ($claimsCount > 0) {
            $error = "❌ Нельзя удалить объект «" . htmlspecialchars($obj_name) . "»: с ним связано $claimsCount претензий. Сначала перенесите или удалите их.";
        } else {
            $pdo->prepare("DELETE FROM object_companies WHERE object_id=?")->execute([$obj_id]);
            $pdo->prepare("DELETE FROM objects WHERE id=?")->execute([$obj_id]);
            logAction('delete', 'object', $obj_id, 'Удалён объект: ' . $obj_name);
            $message = '🗑️ Объект «' . htmlspecialchars($obj_name) . '» удалён';
        }
    }
}

// ===== Привязка компании к объекту =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['link_company'])) {
    $company_id = (int)($_POST['link_company_id'] ?? 0);
    $object_id  = (int)($_POST['link_object_id'] ?? 0);
    $role       = $_POST['link_role'] ?? 'other';

    if ($company_id && $object_id) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM object_companies WHERE object_id=? AND company_id=? AND role=?");
        $stmt->execute([$object_id, $company_id, $role]);
        if ($stmt->fetchColumn() > 0) {
            $error = 'Эта компания уже привязана к объекту с такой ролью';
        } else {
            $stmt = $pdo->prepare("INSERT INTO object_companies (object_id, company_id, role) VALUES (?, ?, ?)");
            $stmt->execute([$object_id, $company_id, $role]);
            logAction('link', 'object_company', 0, "Компания ID=$company_id привязана к объекту ID=$object_id как $role");
            $message = '✅ Компания привязана к объекту';
        }
    }
}

// ===== Удаление привязки =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST["unlink_obj"], $_POST["unlink_comp"], $_POST["unlink_role"])) {
    $object_id  = (int)$_POST["unlink_obj"];
    $company_id = (int)$_POST["unlink_comp"];
    $role       = $_POST["unlink_role"];
    $stmt = $pdo->prepare("DELETE FROM object_companies WHERE object_id=? AND company_id=? AND role=?");
    $stmt->execute([$object_id, $company_id, $role]);
    logAction("unlink", "object_company", 0, "Отвязана компания ID=$company_id от объекта ID=$object_id");
    $message = "✅ Привязка удалена";
}

// ===== Данные =====
$companies = $pdo->query("SELECT c.*,
    (SELECT COUNT(*) FROM claims cl WHERE cl.recipient_id = c.id) as claims_count
    FROM companies c ORDER BY c.name")->fetchAll();
$objects = $pdo->query("SELECT o.*,
    (SELECT COUNT(*) FROM claims cl WHERE cl.object_id = o.id) as claims_count
    FROM objects o ORDER BY o.name")->fetchAll();
$roleLabels = [
    'developer'            => '🏗️ Застройщик',
    'technical_customer'   => '🔧 Тех. заказчик',
    'general_contractor'   => '👷 Ген. подрядчик',
    'other'                => '📋 Другое',
];

// ===== Переменные для шаблона =====
$pageTitle = 'Компании и объекты — Реестр претензий';
$currentPage = 'objects';
$showUsersLink = false;
$isAdmin = false;
$extraHead = '';
$sidebarContent = '';

require 'templates/header.php';
?>
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h3 class="fw-bold mb-0"><i class="bi bi-building"></i> Компании и объекты</h3>
                <a href="index.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> К реестру</a>
            </div>

            <?php if ($message): ?>
                <div class="alert alert-success alert-dismissible fade show"><?= $message ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show"><?= $error ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
            <?php endif; ?>

            <!-- ===== Секция: Компании ===== -->
            <div class="card shadow-sm border-0 mb-4">
                <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                    <h5 class="fw-bold mb-0"><i class="bi bi-people text-primary"></i> Компании-получатели</h5>
                    <button class="btn btn-primary btn-sm" data-bs-toggle="collapse" data-bs-target="#addCompanyForm">
                        <i class="bi bi-plus-lg"></i> Добавить компанию
                    </button>
                </div>
                <div class="collapse" id="addCompanyForm">
                    <div class="card-body border-bottom bg-light">
                        <form method="POST" class="row g-2 align-items-end">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Название компании</label>
                                <input type="text" name="company_name" class="form-control" placeholder="ООО «СтройГарант»" required>
                            </div>
                            <div class="col-md-3">
                                <button type="submit" name="add_company" class="btn btn-success w-100">
                                    <i class="bi bi-check-lg"></i> Добавить
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
                <div class="card-body p-0">
                    <?php if (count($companies) === 0): ?>
                        <div class="text-center py-5 text-muted">
                            <i class="bi bi-people fs-1 d-block mb-2"></i>
                            <p>Нет ни одной компании. Добавьте первую!</p>
                        </div>
                    <?php else: ?>
                        <div class="accordion" id="companiesAccordion">
                            <?php foreach ($companies as $c):
                                $objLinks = getObjectsByCompany($pdo, $c['id']);
                            ?>
                                <div class="accordion-item border-0 border-bottom">
                                    <h2 class="accordion-header">
                                        <button class="accordion-button collapsed bg-white" type="button" data-bs-toggle="collapse" data-bs-target="#company_<?= $c['id'] ?>">
                                            <div class="d-flex align-items-center gap-3">
                                                <strong><?= htmlspecialchars($c['name']) ?></strong>
                                                <span class="badge bg-info"><?= count($objLinks) ?> объектов</span>
                                                <?php if ($c['claims_count'] > 0): ?>
                                                    <span class="badge bg-warning text-dark"><?= $c['claims_count'] ?> претензий</span>
                                                <?php endif; ?>
                                            </div>
                                        </button>
                                    </h2>
                                    <div id="company_<?= $c['id'] ?>" class="accordion-collapse collapse" data-bs-parent="#companiesAccordion">
                                        <div class="accordion-body bg-light">
                                            <?php if (count($objLinks) > 0): ?>
                                                <table class="table table-sm table-bordered mb-3">
                                                    <thead class="table-secondary">
                                                        <tr>
                                                            <th>Объект</th>
                                                            <th>Роль</th>
                                                            <th style="width:50px;"></th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <?php foreach ($objLinks as $ol): ?>
                                                            <tr>
                                                                <td><?= htmlspecialchars($ol['name']) ?></td>
                                                                <td><?= $roleLabels[$ol['role']] ?? $ol['role'] ?></td>
                                                                <td>
                                                                    <form method="POST" class="d-inline" onsubmit="return confirm('Отвязать компанию «<?= htmlspecialchars($c['name'], ENT_QUOTES) ?>» от объекта «<?= htmlspecialchars($ol['name'], ENT_QUOTES) ?>»?')">
                                                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                                                        <input type="hidden" name="unlink_obj" value="<?= $ol['id'] ?>">
                                                                        <input type="hidden" name="unlink_comp" value="<?= $c['id'] ?>">
                                                                        <input type="hidden" name="unlink_role" value="<?= htmlspecialchars($ol['role'], ENT_QUOTES) ?>">
                                                                        <button type="submit" class="btn btn-sm btn-outline-danger" title="Отвязать">
                                                                            <i class="bi bi-x-lg"></i>
                                                                        </button>
                                                                    </form>
                                                                </td>
                                                            </tr>
                                                        <?php endforeach; ?>
                                                    </tbody>
                                                </table>
                                            <?php else: ?>
                                                <p class="text-muted mb-3"><i class="bi bi-info-circle"></i> Компания не привязана ни к одному объекту</p>
                                            <?php endif; ?>

                                            <form method="POST" class="row g-2 align-items-end border-top pt-3">
                                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                                <input type="hidden" name="link_company_id" value="<?= $c['id'] ?>">
                                                <div class="col-md-5">
                                                    <label class="form-label small fw-semibold">Привязать к объекту</label>
                                                    <select name="link_object_id" class="form-select form-select-sm" required>
                                                        <option value="">— Выберите объект —</option>
                                                        <?php foreach ($objects as $o):
                                                            $already = getCompanyObjectRole($pdo, $o['id'], $c['id']);
                                                        ?>
                                                            <option value="<?= $o['id'] ?>" <?= $already ? 'disabled style="color:#999"' : '' ?>>
                                                                <?= htmlspecialchars($o['name']) ?>
                                                                <?= $already ? '(уже привязана)' : '' ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                                <div class="col-md-3">
                                                    <label class="form-label small fw-semibold">Роль</label>
                                                    <select name="link_role" class="form-select form-select-sm">
                                                        <option value="developer">🏗️ Застройщик</option>
                                                        <option value="technical_customer">🔧 Тех. заказчик</option>
                                                        <option value="general_contractor">👷 Ген. подрядчик</option>
                                                        <option value="other">📋 Другое</option>
                                                    </select>
                                                </div>
                                                <div class="col-md-4">
                                                    <button type="submit" name="link_company" class="btn btn-sm btn-success w-100">
                                                        <i class="bi bi-link"></i> Привязать
                                                    </button>
                                                </div>
                                            </form>
                                            <?php if ($c['claims_count'] == 0): ?>
                                                <form method="POST" class="mt-2" onsubmit="return confirm('Удалить компанию «<?= htmlspecialchars($c['name'], ENT_QUOTES) ?>»? Это также уберёт все её привязки к объектам.')">
                                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                                    <input type="hidden" name="delete_company" value="<?= $c['id'] ?>">
                                                    <button type="submit" class="btn btn-sm btn-outline-danger" title="Удалить компанию">
                                                        <i class="bi bi-trash"></i> Удалить компанию
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="card-footer bg-white text-muted small">
                    Всего компаний: <strong><?= count($companies) ?></strong>
                </div>
            </div>

            <!-- ===== Секция: Объекты строительства ===== -->
            <div class="card shadow-sm border-0">
                <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                    <h5 class="fw-bold mb-0"><i class="bi bi-geo-alt text-secondary"></i> Объекты строительства</h5>
                    <button class="btn btn-primary btn-sm" data-bs-toggle="collapse" data-bs-target="#addObjectForm">
                        <i class="bi bi-plus-lg"></i> Добавить объект
                    </button>
                </div>
                <div class="collapse" id="addObjectForm">
                    <div class="card-body border-bottom bg-light">
                        <form method="POST" class="row g-2 align-items-end">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                            <div class="col-md-5">
                                <label class="form-label fw-semibold">Название объекта</label>
                                <input type="text" name="object_name" class="form-control" placeholder="ЖК «Солнечный»" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-semibold">Адрес</label>
                                <input type="text" name="object_address" class="form-control" placeholder="г. Москва, ул. Строителей, д. 1">
                            </div>
                            <div class="col-md-3">
                                <button type="submit" name="add_object" class="btn btn-success w-100">
                                    <i class="bi bi-check-lg"></i> Добавить
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
                <div class="card-body p-0">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-dark">
                            <tr>
                                <th>Название</th>
                                <th>Адрес</th>
                                <th>Привязанные компании</th>
                                <th style="width:110px;">Действия</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($objects as $o):
                                $objComps = getCompaniesByObject($pdo, $o['id']);
                            ?>
                                <tr>
                                    <td><strong><?= htmlspecialchars($o['name']) ?></strong></td>
                                    <td><?= htmlspecialchars($o['address'] ?: '—') ?></td>
                                    <td>
                                        <?php if (count($objComps) === 0): ?>
                                            <span class="text-muted">—</span>
                                        <?php else: ?>
                                            <?php foreach ($objComps as $oc): ?>
                                                <span class="badge bg-info me-1 mb-1">
                                                    <?= htmlspecialchars($oc['name']) ?>
                                                    <small>(<?= $roleLabels[$oc['role']] ?? $oc['role'] ?>)</small>
                                                </span>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="btn-group btn-group-sm">
                                            <button type="button" class="btn btn-outline-primary" title="Редактировать"
                                                    data-bs-toggle="modal" data-bs-target="#editObjectModal<?= $o['id'] ?>">
                                                <i class="bi bi-pencil"></i>
                                            </button>
                                            <?php if ((int)$o['claims_count'] > 0): ?>
                                                <button type="button" class="btn btn-outline-danger" disabled
                                                        title="Нельзя удалить: с объектом связано <?= (int)$o['claims_count'] ?> претензий">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            <?php else: ?>
                                                <form method="POST" class="d-inline" onsubmit="return confirm('Удалить объект «<?= htmlspecialchars($o['name'], ENT_QUOTES) ?>»? Привязки компаний будут убраны.')">
                                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                                    <input type="hidden" name="delete_object" value="<?= $o['id'] ?>">
                                                    <button type="submit" class="btn btn-outline-danger" title="Удалить объект">
                                                        <i class="bi bi-trash"></i>
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (count($objects) === 0): ?>
                                <tr><td colspan="4" class="text-center py-4 text-muted">Объектов пока нет. Добавьте первый!</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Модальные окна редактирования объектов -->
            <?php foreach ($objects as $o): ?>
            <div class="modal fade" id="editObjectModal<?= $o['id'] ?>" tabindex="-1" aria-hidden="true">
                <div class="modal-dialog">
                    <div class="modal-content">
                        <form method="POST">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                            <input type="hidden" name="edit_object" value="<?= $o['id'] ?>">
                            <div class="modal-header">
                                <h5 class="modal-title"><i class="bi bi-pencil"></i> Редактировать объект</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Закрыть"></button>
                            </div>
                            <div class="modal-body">
                                <div class="mb-3">
                                    <label class="form-label fw-semibold">Название объекта <span class="text-danger">*</span></label>
                                    <input type="text" name="edit_object_name" class="form-control" maxlength="255" value="<?= htmlspecialchars($o['name']) ?>" required>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label fw-semibold">Адрес</label>
                                    <input type="text" name="edit_object_address" class="form-control" maxlength="500" value="<?= htmlspecialchars($o['address'] ?? '') ?>" placeholder="г. Москва, ул. Строителей, д. 1">
                                </div>
                                <?php if ((int)$o['claims_count'] > 0): ?>
                                    <small class="text-muted"><i class="bi bi-info-circle"></i> При переименовании объекта все существующие претензии сохранят привязку автоматически</small>
                                <?php endif; ?>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Отмена</button>
                                <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> Сохранить</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
<?php
require 'templates/footer.php';