<?php
// ===== Загрузка .env =====
$envPaths = [
    __DIR__ . '/../.env_secure/.env',        // /var/www/.env_secure/.env
    __DIR__ . '/../.env',                      // /var/www/.env (если вдруг)
    __DIR__ . '/.env',                         // старый путь (если вдруг)
];

$env = [];
$envLoaded = false;

foreach ($envPaths as $envFile) {
    $realPath = realpath($envFile);
    if ($realPath !== false && is_file($realPath) && is_readable($realPath)) {
        $lines = @file($realPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines !== false) {
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#') || str_starts_with($line, ';')) continue;
                $pos = strpos($line, '=');
                if ($pos !== false) {
                    $key = trim(substr($line, 0, $pos));
                    $value = trim(substr($line, $pos + 1));
                    $value = trim($value, "'\"");
                    $env[$key] = $value;
                }
            }
            $envLoaded = true;
            break; // нашли первый существующий .env
        }
    }
}

if (!$envLoaded) {
    die('❌ Не найден файл .env. Обратитесь к администратору.');
}

// ===== Настройки БД =====
define('DB_HOST', $env['DB_HOST'] ?? 'localhost');
define('DB_NAME', $env['DB_NAME'] ?? '');
define('DB_USER', $env['DB_USER'] ?? '');
define('DB_PASS', $env['DB_PASS'] ?? '');

// ===== Проверка: все параметры БД должны быть заполнены =====
$missing = [];
if (empty(DB_NAME)) $missing[] = 'DB_NAME';
if (empty(DB_USER)) $missing[] = 'DB_USER';
if (empty(DB_PASS)) $missing[] = 'DB_PASS';

if (!empty($missing)) {
    die('❌ В .env не заполнены: ' . implode(', ', $missing));
}

// ===== Папка загрузок (ВНЕ корня сайта!) =====
// По умолчанию: /var/www/claims-uploads (рядом с папкой html).
// Можно переопределить в .env строкой: UPLOAD_DIR=/var/www/claims-uploads
$uploadDirConfig = $env['UPLOAD_DIR'] ?? dirname(__DIR__) . '/claims-uploads';
define('UPLOAD_DIR', rtrim($uploadDirConfig, '/\\') . '/');
if (!is_dir(UPLOAD_DIR)) {
    @mkdir(UPLOAD_DIR, 0770, true);
}

// ===== Настройки сессии =====
ini_set('session.use_strict_mode', 1);
ini_set('session.use_only_cookies', 1);
ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_samesite', 'Lax');
ini_set('session.gc_maxlifetime', 7200);

if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
    ini_set('session.cookie_secure', 1);
}
if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') {
    ini_set('session.cookie_secure', 1);
}

session_start();

// ===== Принудительный HTTPS =====
//$host = $_SERVER['HTTP_HOST'] ?? '';
//$isLocalhost = in_array($host, ['localhost', '127.0.0.1', '::1']);


//if (!$isLocalhost) {
//    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
//            || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
//    if (!$isHttps) {
//        header('Location: https://' . $host . $_SERVER['REQUEST_URI']);
//        exit;
//    }
//}
// ===== Проверка HTTPS (без принудительного редиректа) =====
$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');

// Редирект на HTTPS отключён для работы внутри сети

// ===== Подключение к БД =====
try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
} catch (PDOException $e) {
    die('❌ Ошибка подключения к БД');
}

// ===== Функции авторизации =====
function requireAuth() {
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > 7200)) {
        session_destroy();
        header('Location: login.php?expired=1');
        exit;
    }
    if (!isset($_SESSION['user_id'])) {
        $redirect = $_SERVER['REQUEST_URI'] ?? '/index.php';
        header('Location: login.php?redirect=' . rawurlencode($redirect));
        exit;
    }
    $_SESSION['last_activity'] = time();
}

function currentUser() {
    global $pdo;
    if (!isset($_SESSION['user_id'])) return null;
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    return $stmt->fetch();
}

function hasRole(...$roles) {
    return isset($_SESSION['role']) && in_array($_SESSION['role'], $roles);
}

function requireRole(...$roles) {
    requireAuth();
    if (!hasRole(...$roles)) {
        http_response_code(403);
        die('❌ Доступ запрещён');
    }
}

// ===== CSRF =====
function getCsrfToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verifyCsrfToken($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token ?? '');
}

function requireCsrfToken() {
    $token = $_POST['csrf_token'] ?? '';
    if (!verifyCsrfToken($token)) {
        http_response_code(419);
        die('❌ CSRF-токен недействителен');
    }
}

// ===== Функции претензий =====
function getDaysPassed($claim) {
    if (empty($claim['received_date'])) return 0;
    $received = new DateTime($claim['received_date']);
    $endDate = !empty($claim['response_date']) ? new DateTime($claim['response_date']) : new DateTime();
    return $received->diff($endDate)->days;
}

function getClaimStatusColor($claim) {
    $days = getDaysPassed($claim);
    if (!empty($claim['response_date'])) return 'green';
    if ($days < 10)  return 'green';
    if ($days < 20)  return 'yellow';
    if ($days < 30)  return 'red';
    return 'purple';
}

function getStatusLabel($color) {
    $labels = [
        'green'  => '✅ В срок',
        'yellow' => '⚠️ Внимание (10–19 дн.)',
        'red'    => '🔴 Просрочка (20–29 дн.)',
        'purple' => '🟣 Просрочен (30+ дн.)',
    ];
    return $labels[$color] ?? 'Неизвестно';
}

// ===== Логирование =====
function logAction($action, $entity_type = null, $entity_id = null, $details = null) {
    global $pdo;
    try {
        $stmt = $pdo->prepare("INSERT INTO logs
            (user_id, username, full_name, action, entity_type, entity_id, details, ip_address, user_agent)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $_SESSION['user_id'] ?? 0,
            mb_substr($_SESSION['username'] ?? 'anonymous', 0, 100),
            mb_substr($_SESSION['full_name'] ?? '', 0, 255),
            mb_substr($action, 0, 50),
            $entity_type ? mb_substr($entity_type, 0, 50) : null,
            $entity_id,
            $details ? mb_substr($details, 0, 1000) : null,
            $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
            mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500)
        ]);
    } catch (PDOException $e) {}
}

function logFailedLogin($username) {
    global $pdo;
    // 1) счётчик для антибрутфорса — отдельная таблица
    try {
        $stmt = $pdo->prepare("INSERT INTO login_attempts (ip_address, username) VALUES (?, ?)");
        $stmt->execute([
            $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
            mb_substr($username, 0, 50),
        ]);
    } catch (PDOException $e) {}
    // 2) аудит — навсегда остаётся в журнале
    logAction('login_failed', 'user', null, 'Неудачная попытка входа: ' . mb_substr($username, 0, 50));
}

// ===== Валидация файлов =====
function isAllowedFileType($filePath) {
    if (!file_exists($filePath)) return false;
    $allowedMimes = [
        'application/pdf', 'image/jpeg', 'image/png', 'image/gif',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.ms-powerpoint',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'text/plain',
    ];
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $filePath);
    finfo_close($finfo);
    return in_array($mime, $allowedMimes);
}

// ===== Порядковый номер претензии в общем списке =====
function getClaimNumber($pdo, $id) {
    // Считаем, сколько записей идёт до этой (по ID)
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM claims WHERE id <= ?");
    $stmt->execute([$id]);
    return (int)$stmt->fetchColumn();
}


// ===== Функции для работы с компаниями =====
function getAllCompanies($pdo) {
    $stmt = $pdo->query("SELECT * FROM companies ORDER BY name");
    return $stmt->fetchAll();
}

function getCompanyNameById($pdo, $id) {
    if (!$id) return null;
    $stmt = $pdo->prepare("SELECT name FROM companies WHERE id=?");
    $stmt->execute([$id]);
    return $stmt->fetchColumn();
}

function getCompaniesByObject($pdo, $objectId) {
    $stmt = $pdo->prepare("
        SELECT c.*, oc.role
        FROM companies c
        JOIN object_companies oc ON c.id = oc.company_id
        WHERE oc.object_id = ?
        ORDER BY c.name
    ");
    $stmt->execute([$objectId]);
    return $stmt->fetchAll();
}

function getObjectsByCompany($pdo, $companyId) {
    $stmt = $pdo->prepare("
        SELECT o.*, oc.role
        FROM objects o
        JOIN object_companies oc ON o.id = oc.object_id
        WHERE oc.company_id = ?
        ORDER BY o.name
    ");
    $stmt->execute([$companyId]);
    return $stmt->fetchAll();
}

function getCompanyObjectRole($pdo, $objectId, $companyId) {
    $stmt = $pdo->prepare("SELECT role FROM object_companies WHERE object_id=? AND company_id=?");
    $stmt->execute([$objectId, $companyId]);
    return $stmt->fetchColumn();
}

// ===== Безопасность файлов =====
// Whitelist расширений для загрузки через формы add.php/edit.php (зеркалит isAllowedFileType)
const ALLOWED_UPLOAD_EXTENSIONS = ['pdf','jpg','jpeg','png','gif','doc','docx','xls','xlsx','ppt','pptx','txt'];

// Расширенный список для импорта из архива (файлы хранятся вне webroot и
// отдаются через download.php, поэтому исполняемыми быть не могут в принципе)
const ALLOWED_IMPORT_EXTENSIONS = [
    'pdf','doc','docx','xls','xlsx','ppt','pptx','odt','ods','odp','rtf','txt',
    'jpg','jpeg','png','gif','bmp','tif','tiff','heic','zip','rar','7z','msg','eml',
];

// Возвращает нормализованное расширение файла, если оно в whitelist, иначе ''
function uploadExtension(string $originalName, array $allowed = ALLOWED_UPLOAD_EXTENSIONS): string {
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    return in_array($ext, $allowed, true) ? $ext : '';
}

// Ссылка на файл через защищённый шлюз download.php (с проверкой авторизации)
function uploadLink($fileName): string {
    return 'download.php?file=' . rawurlencode(basename((string)$fileName));
}

// Используется ли файл другими претензиями (кроме исключаемой)
function claimFileInUse(PDO $pdo, string $fileName, int $excludeClaimId = 0): bool {
    if ($fileName === '') return false;
    $fileName = basename($fileName);
    $like = '%' . addcslashes($fileName, '\\%_') . '%';
    $sql = "SELECT COUNT(*) FROM claims
            WHERE claim_file = ? OR response_file = ? OR additional_files LIKE ?";
    $params = [$fileName, $fileName, $like];
    if ($excludeClaimId > 0) {
        $sql .= " AND id != ?";
        $params[] = $excludeClaimId;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (int)$stmt->fetchColumn() > 0;
}

// Удалить файл с диска, если на него больше не ссылается ни одна претензия
function deleteUploadIfUnused(PDO $pdo, ?string $fileName, int $excludeClaimId = 0): void {
    if ($fileName === null || $fileName === '') return;
    if (claimFileInUse($pdo, $fileName, $excludeClaimId)) return;
    $path = UPLOAD_DIR . basename($fileName);
    if (is_file($path)) { @unlink($path); }
    // Переходный период: старая папка в webroot (можно убрать после переноса файлов)
    $legacyPath = __DIR__ . '/uploads/' . basename($fileName);
    if (is_file($legacyPath)) { @unlink($legacyPath); }
}

// Защита от CSV-инъекции: экранирование ячеек, начинающихся с = + - @ и табуляции
function csvSafe($value) {
    if (is_string($value) && $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
        return "'" . $value;
    }
    return $value;
}