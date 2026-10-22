<?php
require 'config.php';
requireAuth();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfToken();
}
$csrfToken = getCsrfToken();
$user = currentUser();
$isAdmin = ($_SESSION['role'] === 'admin' || $_SESSION['user_id'] === 1);

$error = '';
$message = '';

// ===== Обработка форм =====

// 1. Создание нового пользователя
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_user']) && $isAdmin) {
    $username   = trim($_POST['username'] ?? '');
    $password   = $_POST['password'] ?? '';
    $full_name  = trim($_POST['full_name'] ?? '');
    $role       = $_POST['role'] ?? 'viewer';
    $is_active  = isset($_POST['is_active']) ? 1 : 0;

    if (empty($username)) {
        $error = 'Логин обязателен';
    } elseif (strlen($username) < 3) {
        $error = 'Логин должен быть минимум 3 символа';
    } elseif (empty($password)) {
        $error = 'Пароль обязателен';
    } elseif (strlen($password) < 4) {
        $error = 'Пароль должен быть минимум 4 символа';
    } else {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
        $stmt->execute([$username]);
        if ($stmt->fetch()) {
            $error = 'Пользователь с таким логином уже существует';
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("INSERT INTO users (username, password_hash, full_name, role, is_active) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$username, $hash, $full_name ?: null, $role, $is_active]);
            logAction('create', 'user', $pdo->lastInsertId(), 'Создан пользователь: ' . $username);
            $message = '✅ Пользователь «' . htmlspecialchars($username) . '» создан';
        }
    }
}

// 2. Смена пароля
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    $targetUserId = (int)($_POST['user_id'] ?? 0);
    $newPassword  = $_POST['new_password'] ?? '';

    $canChange = ($isAdmin || $targetUserId === $_SESSION['user_id']);

    if (!$canChange) {
        $error = 'У вас нет прав на смену пароля этого пользователя';
    } elseif (empty($newPassword)) {
        $error = 'Введите новый пароль';
    } elseif (strlen($newPassword) < 4) {
        $error = 'Пароль должен быть минимум 4 символа';
    } else {
        $hash = password_hash($newPassword, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
        $stmt->execute([$hash, $targetUserId]);
        logAction('password_change', 'user', $targetUserId, 'Смена пароля пользователя ID=' . $targetUserId);
        $message = '✅ Пароль успешно изменён';
    }
}

// 3. Деактивация / активация пользователя (только админ)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_active']) && $isAdmin) {
    $targetId = (int)$_POST['toggle_active'];
    if ($targetId === 1) {
        $error = '❌ Нельзя деактивировать главного администратора';
    } elseif ($targetId === $_SESSION['user_id']) {
        $error = '❌ Нельзя деактивировать самого себя';
    } else {
        $stmt = $pdo->prepare("UPDATE users SET is_active = NOT is_active WHERE id = ?");
        $stmt->execute([$targetId]);
        logAction('toggle_active', 'user', $targetId, 'Изменён статус пользователя ID=' . $targetId);
        $message = '✅ Статус пользователя изменён';
    }
}

// 4. Удаление пользователя (только админ)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_user']) && $isAdmin) {
    $targetId = (int)$_POST['delete_user'];
    if ($targetId === 1) {
        $error = '❌ Нельзя удалить главного администратора';
    } elseif ($targetId === $_SESSION['user_id']) {
        $error = '❌ Нельзя удалить самого себя';
    } else {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM claims WHERE created_by = ?");
        $stmt->execute([$targetId]);
        $count = $stmt->fetchColumn();

        if ($count > 0) {
            $error = "❌ Нельзя удалить: пользователь создал $count претензий. Переназначьте их на другого пользователя.";
        } else {
            $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
            $stmt->execute([$targetId]);
            logAction('delete', 'user', $targetId, 'Удалён пользователь ID=' . $targetId);
            $message = '🗑️ Пользователь удалён';
        }
    }
}

// Получаем список пользователей
$users = $pdo->query("SELECT u.*, (SELECT COUNT(*) FROM claims WHERE created_by = u.id) as claims_count FROM users u ORDER BY u.id")->fetchAll();

// ===== Переменные для шаблона =====
$pageTitle = 'Управление пользователями — Реестр претензий';
$currentPage = 'users';
$showUsersLink = false;
$extraHead = <<<EXTRAHEAD
<style>
    .password-field { position: relative; }
    .password-field .toggle-password {
        position: absolute;
        right: 10px;
        top: 35px;
        cursor: pointer;
        color: #6c757d;
        background: none;
        border: none;
    }
    .password-field .toggle-password:hover { color: #000; }
</style>
EXTRAHEAD;

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
                <h3 class="fw-bold mb-0"><i class="bi bi-people"></i> Управление пользователями</h3>
                <a href="index.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> К реестру</a>
            </div>

            <!-- Уведомления -->
            <?php if ($message): ?>
                <div class="alert alert-success alert-dismissible fade show"><?= $message ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show"><?= $error ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
            <?php endif; ?>

            <div class="row g-4">
                <!-- ЛЕВАЯ КОЛОНКА: формы -->
                <div class="col-md-5">
                    <!-- Создание пользователя (только админ) -->
                    <?php if ($isAdmin): ?>
                    <div class="card shadow-sm border-0 mb-4">
                        <div class="card-body p-4">
                            <h5 class="fw-bold mb-3"><i class="bi bi-person-plus text-success"></i> Создать пользователя</h5>
                            <form method="POST">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                <div class="mb-3">
                                    <label class="form-label fw-semibold">Логин <span class="text-danger">*</span></label>
                                    <input type="text" name="username" class="form-control" placeholder="ivanov" required minlength="3">
                                </div>
                                <div class="mb-3 password-field">
                                    <label class="form-label fw-semibold">Пароль <span class="text-danger">*</span></label>
                                    <input type="password" name="password" class="form-control" required minlength="4" id="newPass">
                                    <button type="button" class="toggle-password" onclick="togglePass('newPass', this)">
                                        <i class="bi bi-eye"></i>
                                    </button>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label fw-semibold">ФИО</label>
                                    <input type="text" name="full_name" class="form-control" placeholder="Иванов Иван Иванович">
                                </div>
                                <div class="mb-3">
                                    <label class="form-label fw-semibold">Роль</label>
                                    <select name="role" class="form-select">
                                        <option value="viewer">👁️ Просмотр</option>
                                        <option value="manager">📋 Менеджер</option>
                                        <option value="admin">⚙️ Администратор</option>
                                    </select>
                                </div>
                                <div class="mb-3 form-check">
                                    <input type="checkbox" name="is_active" class="form-check-input" id="isActive" checked>
                                    <label class="form-check-label" for="isActive">Активен (может входить)</label>
                                </div>
                                <button type="submit" name="create_user" class="btn btn-success w-100">
                                    <i class="bi bi-plus-lg"></i> Создать пользователя
                                </button>
                            </form>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Смена пароля (для себя или админ для любого) -->
                    <div class="card shadow-sm border-0">
                        <div class="card-body p-4">
                            <h5 class="fw-bold mb-3"><i class="bi bi-key text-warning"></i> Сменить пароль</h5>
                            <form method="POST">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                <div class="mb-3">
                                    <label class="form-label fw-semibold">Пользователь</label>
                                    <select name="user_id" class="form-select">
                                        <?php if ($isAdmin): ?>
                                            <?php foreach ($users as $u): ?>
                                                <option value="<?= $u['id'] ?>" <?= $u['id'] === $_SESSION['user_id'] ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($u['username']) ?> — <?= htmlspecialchars($u['full_name'] ?? '—') ?>
                                                </option>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <option value="<?= $_SESSION['user_id'] ?>">
                                                <?= htmlspecialchars($user['username']) ?> (только себе)
                                            </option>
                                        <?php endif; ?>
                                    </select>
                                </div>
                                <div class="mb-3 password-field">
                                    <label class="form-label fw-semibold">Новый пароль <span class="text-danger">*</span></label>
                                    <input type="password" name="new_password" class="form-control" required minlength="4" id="changePass">
                                    <button type="button" class="toggle-password" onclick="togglePass('changePass', this)">
                                        <i class="bi bi-eye"></i>
                                    </button>
                                </div>
                                <button type="submit" name="change_password" class="btn btn-warning w-100">
                                    <i class="bi bi-check-lg"></i> Сохранить новый пароль
                                </button>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- ПРАВАЯ КОЛОНКА: список пользователей -->
                <div class="col-md-7">
                    <div class="card shadow-sm border-0">
                        <div class="card-body p-0">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="table-dark">
                                    <tr>
                                        <th>ID</th>
                                        <th>Логин</th>
                                        <th>ФИО</th>
                                        <th>Роль</th>
                                        <th>Статус</th>
                                        <th>Претензий</th>
                                        <th>Создан</th>
                                        <?php if ($isAdmin): ?>
                                        <th style="width:90px;">Действия</th>
                                        <?php endif; ?>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (count($users) === 0): ?>
                                        <tr><td colspan="8" class="text-center py-4 text-muted">
                                            <i class="bi bi-people fs-2 d-block mb-2"></i>
                                            Нет пользователей
                                        </td></tr>
                                    <?php else: foreach ($users as $u): ?>
                                        <tr class="<?= !$u['is_active'] ? 'table-secondary' : '' ?>">
                                            <td class="fw-bold"><?= $u['id'] ?></td>
                                            <td>
                                                <strong><?= htmlspecialchars($u['username']) ?></strong>
                                                <?php if ($u['id'] === 1): ?>
                                                    <span class="badge bg-danger">Главный</span>
                                                <?php endif; ?>
                                                <?php if ($u['id'] === $_SESSION['user_id']): ?>
                                                    <span class="badge bg-info">Вы</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?= htmlspecialchars($u['full_name'] ?? '—') ?></td>
                                            <td>
                                                <?php if ($u['role'] === 'admin'): ?>
                                                    <span class="badge bg-danger">⚙️ Администратор</span>
                                                <?php elseif ($u['role'] === 'manager'): ?>
                                                    <span class="badge bg-primary">📋 Менеджер</span>
                                                <?php else: ?>
                                                    <span class="badge bg-secondary">👁️ Просмотр</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($u['is_active']): ?>
                                                    <span class="badge bg-success">✅ Активен</span>
                                                <?php else: ?>
                                                    <span class="badge bg-secondary">🔴 Неактивен</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><span class="badge bg-info"><?= $u['claims_count'] ?></span></td>
                                            <td><small><?= date('d.m.Y', strtotime($u['created_at'])) ?></small></td>
                                            <?php if ($isAdmin): ?>
                                            <td>
                                                <div class="btn-group btn-group-sm">
                                                    <a href="#" class="btn btn-outline-warning" title="Сменить пароль"
                                                       onclick="document.querySelector('select[name=user_id]').value='<?= $u['id'] ?>'; document.querySelector('input[name=new_password]').focus(); return false;">
                                                        <i class="bi bi-key"></i>
                                                    </a>
                                                    <?php if ($u['id'] !== 1 && $u['id'] !== $_SESSION['user_id']): ?>
                                                        <form method="POST" class="d-inline" onsubmit="return confirm('<?= $u['is_active'] ? 'Деактивировать' : 'Активировать' ?> пользователя «<?= htmlspecialchars($u['username'], ENT_QUOTES) ?>»?')">
                                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                                            <input type="hidden" name="toggle_active" value="<?= $u['id'] ?>">
                                                            <button type="submit" class="btn btn-outline-<?= $u['is_active'] ? 'secondary' : 'success' ?>" title="<?= $u['is_active'] ? 'Деактивировать' : 'Активировать' ?>">
                                                                <i class="bi bi-<?= $u['is_active'] ? 'pause-circle' : 'play-circle' ?>"></i>
                                                            </button>
                                                        </form>
                                                        <form method="POST" class="d-inline" onsubmit="return confirm('Удалить пользователя «<?= htmlspecialchars($u['username'], ENT_QUOTES) ?>»?')">
                                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                                            <input type="hidden" name="delete_user" value="<?= $u['id'] ?>">
                                                            <button type="submit" class="btn btn-outline-danger" title="Удалить">
                                                                <i class="bi bi-trash"></i>
                                                            </button>
                                                        </form>
                                                    <?php else: ?>
                                                        <span class="text-muted" title="Недоступно"><i class="bi bi-lock"></i></span>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                            <?php endif; ?>
                                        </tr>
                                    <?php endforeach; endif; ?>
                                </tbody>
                            </table>
                        </div>
                        <div class="card-footer bg-white text-muted small d-flex justify-content-between">
                            <div>Всего: <strong><?= count($users) ?></strong> пользователей</div>
                            <div>
                                <i class="bi bi-shield-lock"></i>
                                <?= count(array_filter($users, fn($u) => $u['role'] === 'admin')) ?> админов
                            </div>
                        </div>
                    </div>

                    <div class="card shadow-sm border-0 mt-3">
                        <div class="card-body p-3">
                            <h6 class="fw-bold"><i class="bi bi-info-circle text-info"></i> Правила</h6>
                            <ul class="small text-muted mb-0">
                                <li><strong>Главный администратор (id=1)</strong> — не может быть удалён или деактивирован</li>
                                <li><strong>Администратор</strong> — может создавать, редактировать и удалять любых пользователей</li>
                                <li><strong>Менеджер (manager)</strong> — может работать с претензиями, сменить свой пароль</li>
                                <li><strong>Просмотр (viewer)</strong> — только просмотр данных, может сменить свой пароль</li>
                                <li>При удалении пользователя у него не должно быть созданных претензий</li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>

<script>
function togglePass(fieldId, btn) {
    const field = document.getElementById(fieldId);
    if (field.type === 'password') {
        field.type = 'text';
        btn.innerHTML = '<i class="bi bi-eye-slash"></i>';
    } else {
        field.type = 'password';
        btn.innerHTML = '<i class="bi bi-eye"></i>';
    }
}
</script>
<?php
require 'templates/footer.php';