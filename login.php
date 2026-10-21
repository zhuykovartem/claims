<?php
require 'config.php';

// Если уже авторизован — на главную
if (isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

$error = '';
$redirect = $_GET['redirect'] ?? 'index.php';

// ===== Сообщение об истечении сессии =====
if (isset($_GET['expired'])) {
    $error = '⏰ Сессия истекла. Пожалуйста, войдите снова.';
}

// ===== Проверка брутфорса: 5 неудачных попыток за 15 минут =====
$ip = $_SERVER['REMOTE_ADDR'] ?? '';
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) as cnt FROM logs
        WHERE action = 'login_failed' AND ip_address = ?
        AND created_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)");
    $stmt->execute([$ip]);
    $failedCount = (int)($stmt->fetch()['cnt'] ?? 0);
} catch (PDOException $e) {
    $failedCount = 0;
}

$blocked = ($failedCount >= 5);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$blocked) {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $csrf_token = $_POST['csrf_token'] ?? '';

    // CSRF-защита
    if (!verifyCsrfToken($csrf_token)) {
        $error = '❌ Ошибка безопасности. Обновите страницу.';
    } elseif (empty($username) || empty($password)) {
        $error = 'Введите логин и пароль';
    } else {
        try {
            $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? AND is_active = 1 LIMIT 1");
            $stmt->execute([$username]);
            $user = $stmt->fetch();

            if ($user && password_verify($password, $user['password_hash'])) {
                // Успешный вход — сбрасываем счётчик попыток
                $stmt = $pdo->prepare("DELETE FROM logs WHERE action = 'login_failed' AND ip_address = ?");
                $stmt->execute([$ip]);

                // Устанавливаем сессию
                session_regenerate_id(true);
                $_SESSION['user_id'] = (int)$user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['full_name'] = $user['full_name'];
                $_SESSION['role'] = $user['role'];
                $_SESSION['last_activity'] = time();
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); // новый токен после входа

                logAction('login', 'user', $user['id'], 'Вход в систему');

                header('Location: ' . $redirect);
                exit;
            } else {
                logFailedLogin($username);
                $error = 'Неверный логин или пароль';

                // Пересчитываем попытки после записи
                try {
                    $stmt = $pdo->prepare("SELECT COUNT(*) as cnt FROM logs
                        WHERE action = 'login_failed' AND ip_address = ?
                        AND created_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)");
                    $stmt->execute([$ip]);
                    $failedCount = (int)($stmt->fetch()['cnt'] ?? 0);
                    $blocked = ($failedCount >= 5);
                } catch (PDOException $e) {}
            }
        } catch (PDOException $e) {
            $error = 'Ошибка сервера. Попробуйте позже.';
        }
    }
}

// Генерируем CSRF-токен для формы
$csrfToken = getCsrfToken();
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Вход — Реестр претензий</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
        }
        .login-card {
            border: none;
            border-radius: 16px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
            background: rgba(255,255,255,0.95);
        }
        .login-header { text-align: center; padding: 2rem 1rem 0; }
        .login-header h3 { font-weight: 700; color: #333; }
        .login-header p { color: #666; font-size: 0.9rem; }
        .btn-login {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
            color: #fff;
            font-weight: 600;
            padding: 12px;
            border-radius: 10px;
            transition: all 0.3s;
        }
        .btn-login:hover { transform: translateY(-2px); box-shadow: 0 5px 15px rgba(102,126,234,0.4); color: #fff; }
        .btn-login:disabled { opacity: 0.6; cursor: not-allowed; transform: none; }
        .form-control {
            border-radius: 10px;
            padding: 12px 16px;
            border: 2px solid #e0e0e0;
            transition: border-color 0.3s;
        }
        .form-control:focus { border-color: #667eea; box-shadow: none; }
        .brand-icon { font-size: 3rem; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); -webkit-background-clip: text; -webkit-text-fill-color: transparent; }
    </style>
</head>
<body>
<div class="container">
    <div class="row justify-content-center">
        <div class="col-md-5 col-lg-4">
            <div class="login-card p-4">
                <div class="login-header">
                    <div class="brand-icon mb-2">📋</div>
                    <h3>Реестр претензий</h3>
                    <p>Войдите в систему для работы</p>
                </div>

                <?php if ($error): ?>
                    <div class="alert alert-danger alert-dismissible fade show mx-3 mt-3">
                        <i class="bi bi-exclamation-triangle"></i> <?= htmlspecialchars($error) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <?php if ($blocked): ?>
                    <div class="alert alert-warning mx-3 mt-3">
                        <strong>⏳ Доступ временно заблокирован</strong><br>
                        Слишком много неудачных попыток входа.<br>
                        Попробуйте снова через <strong>15 минут</strong>.
                    </div>
                <?php endif; ?>

                <form method="POST" class="px-3 pb-3 mt-3">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Логин</label>
                        <input type="text" name="username" class="form-control"
                               placeholder="admin" required autofocus
                               <?= $blocked ? 'disabled' : '' ?>>
                    </div>
                    <div class="mb-4">
                        <label class="form-label fw-semibold">Пароль</label>
                        <input type="password" name="password" class="form-control"
                               placeholder="••••••••" required
                               <?= $blocked ? 'disabled' : '' ?>>
                    </div>

                    <button type="submit" class="btn btn-login w-50 mx-auto d-block"
                            <?= $blocked ? 'disabled' : '' ?>>
                        <i class="bi bi-box-arrow-in-right"></i> Войти
                    </button>

                    <div class="text-center mt-3">
                        <small class="text-muted">
                            <i class="bi bi-info-circle"></i>
                            Обратитесь к администратору, если нет доступа
                        </small>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>root@claim:~# ^C
root@claim:~# cat /var/www/html/login.php
<?php
require 'config.php';

// Если уже авторизован — на главную
if (isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

$error = '';
$redirect = $_GET['redirect'] ?? 'index.php';

// ===== Сообщение об истечении сессии =====
if (isset($_GET['expired'])) {
    $error = '⏰ Сессия истекла. Пожалуйста, войдите снова.';
}

// ===== Проверка брутфорса: 5 неудачных попыток за 15 минут =====
$ip = $_SERVER['REMOTE_ADDR'] ?? '';
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) as cnt FROM logs
        WHERE action = 'login_failed' AND ip_address = ?
        AND created_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)");
    $stmt->execute([$ip]);
    $failedCount = (int)($stmt->fetch()['cnt'] ?? 0);
} catch (PDOException $e) {
    $failedCount = 0;
}

$blocked = ($failedCount >= 5);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$blocked) {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $csrf_token = $_POST['csrf_token'] ?? '';

    // CSRF-защита
    if (!verifyCsrfToken($csrf_token)) {
        $error = '❌ Ошибка безопасности. Обновите страницу.';
    } elseif (empty($username) || empty($password)) {
        $error = 'Введите логин и пароль';
    } else {
        try {
            $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? AND is_active = 1 LIMIT 1");
            $stmt->execute([$username]);
            $user = $stmt->fetch();

            if ($user && password_verify($password, $user['password_hash'])) {
                // Успешный вход — сбрасываем счётчик попыток
                $stmt = $pdo->prepare("DELETE FROM logs WHERE action = 'login_failed' AND ip_address = ?");
                $stmt->execute([$ip]);

                // Устанавливаем сессию
                session_regenerate_id(true);
                $_SESSION['user_id'] = (int)$user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['full_name'] = $user['full_name'];
                $_SESSION['role'] = $user['role'];
                $_SESSION['last_activity'] = time();
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); // новый токен после входа

                logAction('login', 'user', $user['id'], 'Вход в систему');

                header('Location: ' . $redirect);
                exit;
            } else {
                logFailedLogin($username);
                $error = 'Неверный логин или пароль';

                // Пересчитываем попытки после записи
                try {
                    $stmt = $pdo->prepare("SELECT COUNT(*) as cnt FROM logs
                        WHERE action = 'login_failed' AND ip_address = ?
                        AND created_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)");
                    $stmt->execute([$ip]);
                    $failedCount = (int)($stmt->fetch()['cnt'] ?? 0);
                    $blocked = ($failedCount >= 5);
                } catch (PDOException $e) {}
            }
        } catch (PDOException $e) {
            $error = 'Ошибка сервера. Попробуйте позже.';
        }
    }
}

// Генерируем CSRF-токен для формы
$csrfToken = getCsrfToken();
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Вход — Реестр претензий</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
        }
        .login-card {
            border: none;
            border-radius: 16px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
            background: rgba(255,255,255,0.95);
        }
        .login-header { text-align: center; padding: 2rem 1rem 0; }
        .login-header h3 { font-weight: 700; color: #333; }
        .login-header p { color: #666; font-size: 0.9rem; }
        .btn-login {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
            color: #fff;
            font-weight: 600;
            padding: 12px;
            border-radius: 10px;
            transition: all 0.3s;
        }
        .btn-login:hover { transform: translateY(-2px); box-shadow: 0 5px 15px rgba(102,126,234,0.4); color: #fff; }
        .btn-login:disabled { opacity: 0.6; cursor: not-allowed; transform: none; }
        .form-control {
            border-radius: 10px;
            padding: 12px 16px;
            border: 2px solid #e0e0e0;
            transition: border-color 0.3s;
        }
        .form-control:focus { border-color: #667eea; box-shadow: none; }
        .brand-icon { font-size: 3rem; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); -webkit-background-clip: text; -webkit-text-fill-color: transparent; }
    </style>
</head>
<body>
<div class="container">
    <div class="row justify-content-center">
        <div class="col-md-5 col-lg-4">
            <div class="login-card p-4">
                <div class="login-header">
                    <div class="brand-icon mb-2">📋</div>
                    <h3>Реестр претензий</h3>
                    <p>Войдите в систему для работы</p>
                </div>

                <?php if ($error): ?>
                    <div class="alert alert-danger alert-dismissible fade show mx-3 mt-3">
                        <i class="bi bi-exclamation-triangle"></i> <?= htmlspecialchars($error) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <?php if ($blocked): ?>
                    <div class="alert alert-warning mx-3 mt-3">
                        <strong>⏳ Доступ временно заблокирован</strong><br>
                        Слишком много неудачных попыток входа.<br>
                        Попробуйте снова через <strong>15 минут</strong>.
                    </div>
                <?php endif; ?>

                <form method="POST" class="px-3 pb-3 mt-3">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Логин</label>
                        <input type="text" name="username" class="form-control"
                               placeholder="admin" required autofocus
                               <?= $blocked ? 'disabled' : '' ?>>
                    </div>
                    <div class="mb-4">
                        <label class="form-label fw-semibold">Пароль</label>
                        <input type="password" name="password" class="form-control"
                               placeholder="••••••••" required
                               <?= $blocked ? 'disabled' : '' ?>>
                    </div>

                    <button type="submit" class="btn btn-login w-50 mx-auto d-block"
                            <?= $blocked ? 'disabled' : '' ?>>
                        <i class="bi bi-box-arrow-in-right"></i> Войти
                    </button>

                    <div class="text-center mt-3">
                        <small class="text-muted">
                            <i class="bi bi-info-circle"></i>
                            Обратитесь к администратору, если нет доступа
                        </small>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>