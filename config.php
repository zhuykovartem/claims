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
        // Если POST не дошёл до PHP целиком (суммарный размер файлов превысил
        // post_max_size), $_POST и $_FILES приходят ПУСТЫМИ и токен
        // «недействителен». Показываем понятную причину вместо обманчивой.
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST)
            && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
            http_response_code(413);
            die('❌ Отправленные данные не приняты сервером: суммарный размер файлов '
                . 'превышает лимит PHP (post_max_size / upload_max_filesize в php.ini). '
                . 'Прикрепите файлы меньшими партиями или увеличьте лимиты на сервере.');
        }
        http_response_code(419);
        die('❌ CSRF-токен недействителен');
    }
}

// ===== Flash-предупреждения между страницами (step12b) =====
// Используются, когда претензия сохранена, но часть вложений сбойнула:
// user видит предупреждение на index.php после редиректа.
function flashWarning(string $message): void {
    $_SESSION['flash_warnings'] = array_merge($_SESSION['flash_warnings'] ?? [], [$message]);
}

function takeFlashWarnings(): array {
    $warnings = $_SESSION['flash_warnings'] ?? [];
    unset($_SESSION['flash_warnings']);
    return $warnings;
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

// ===== Step 9: справочник статусов и срок ответа =====
// Типовые статусы для подсказок в формах (datalist). Ввод остаётся
// СВОБОДНЫМ — datalist лишь предлагает варианты, ничего не запрещая.
// Список можно править здесь, в одном месте.
const CLAIM_STATUS_OPTIONS = [
    'Новая',
    'На рассмотрении',
    'Направлен ответ',
    'В суде',
    'Закрыта',
];

// Срок ответа на претензию (дней с даты поступления) — для строки «Срок ответа до»
const CLAIM_RESPONSE_DEADLINE_DAYS = 30;

/**
 * Варианты для datalist статусов: типовые (CLAIM_STATUS_OPTIONS) +
 * реально используемые в базе (топ-15 по частоте).
 * Ошибка запроса не прерывает страницу — вернутся только типовые.
 */
function getStatusOptions(PDO $pdo): array {
    $options = CLAIM_STATUS_OPTIONS;
    try {
        $rows = $pdo->query("SELECT current_status, COUNT(*) AS cnt
            FROM claims
            WHERE current_status IS NOT NULL AND current_status <> ''
            GROUP BY current_status
            ORDER BY cnt DESC, current_status
            LIMIT 15")->fetchAll();
        foreach ($rows as $r) {
            if (!in_array($r['current_status'], $options, true)) {
                $options[] = $r['current_status'];
            }
        }
    } catch (Throwable $e) {
        // БД недоступна — подсказываем только типовые
    }
    return $options;
}

/**
 * Дата, до которой должен быть дан ответ (дата поступления + N дней),
 * или null, если дата поступления не указана/некорректна.
 */
function getResponseDeadline(?string $receivedDate): ?string {
    if (empty($receivedDate)) return null;
    try {
        $dt = new DateTime($receivedDate);
        $dt->modify('+' . CLAIM_RESPONSE_DEADLINE_DAYS . ' days');
        return $dt->format('Y-m-d');
    } catch (Exception $e) {
        return null;
    }
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

// ===== Мультизагрузка вложений (step12) =====
// Максимум файлов в каждом блоке («Файл претензии», «Файл ответа»):
// существующие + новые суммарно. Размер каждого файла ограничивает PHP
// (upload_max_filesize/post_max_size в php.ini).
const ATTACHMENTS_MAX_PER_KIND = 10;

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

// Используется ли файл другими претензиями (кроме исключаемой).
// Проверяются и legacy-поля claims, и таблица вложений claim_attachments (step12).
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
    if ((int)$stmt->fetchColumn() > 0) return true;

    // Таблица вложений: файл занят, если есть строка у другой претензии
    try {
        $sql = "SELECT COUNT(*) FROM claim_attachments WHERE stored_name = ?";
        $params = [$fileName];
        if ($excludeClaimId > 0) {
            $sql .= " AND claim_id != ?";
            $params[] = $excludeClaimId;
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn() > 0;
    } catch (PDOException $e) {
        return false; // миграция step12 ещё не применена
    }
}

// ===== Вложения претензии: таблица claim_attachments (step12) =====

// Все вложения претензии; $kind = 'claim' | 'response' | null (оба типа)
function getClaimAttachments(PDO $pdo, int $claimId, ?string $kind = null): array {
    $sql = "SELECT id, claim_id, file_kind, stored_name, original_name, file_size, uploaded_by, uploaded_at
            FROM claim_attachments WHERE claim_id = ?";
    $params = [$claimId];
    if ($kind !== null && in_array($kind, ['claim', 'response'], true)) {
        $sql .= " AND file_kind = ?";
        $params[] = $kind;
    }
    $sql .= " ORDER BY id";
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        return []; // миграция step12 ещё не применена
    }
}

// Имя вложения для показа пользователю: оригинальное, а для старых
// записей (до step12) — техническое имя на диске
function attachmentDisplayName(array $att): string {
    $orig = trim((string)($att['original_name'] ?? ''));
    return $orig !== '' ? $orig : basename((string)($att['stored_name'] ?? ''));
}

// Ссылка на вложение через защищённый шлюз (оригинальное имя download.php берёт из БД)
function attachmentLink(int $attachmentId): string {
    return 'download.php?att=' . $attachmentId;
}

// Приводит поле $_FILES с multiple-выбором (name="x[]") к списку слотов
// без пустых элементов (пользователь мог не заполнить все строки выбора).
// Возвращает массив: ['name'=>string, 'tmp_name'=>string, 'error'=>int, 'size'=>int]
function normalizeUploadSlots($filesField): array {
    $slots = [];
    if (!is_array($filesField) || !isset($filesField['name']) || !is_array($filesField['name'])) {
        return $slots;
    }
    foreach ($filesField['name'] as $i => $n) {
        $err = (int)($filesField['error'][$i] ?? UPLOAD_ERR_NO_FILE);
        if ($err === UPLOAD_ERR_NO_FILE) {
            // Пустой слот выбора. Но если имя файла задано, а PHP вернул
            // NO_FILE — слот отброшен самим PHP: превышен лимит
            // одновременных загрузок max_file_uploads (php.ini).
            // Превращаем молчаливую потерю файла в явную ошибку (step12b).
            if ((string)$n !== '') {
                $slots[] = [
                    'name' => (string)$n, 'tmp_name' => '', 'error' => UPLOAD_ERR_NO_FILE,
                    'size' => 0, 'dropped_by_php' => true,
                ];
            }
            continue;
        }
        $slots[] = [
            'name'     => (string)$n,
            'tmp_name' => (string)($filesField['tmp_name'][$i] ?? ''),
            'error'    => $err,
            'size'     => (int)($filesField['size'][$i] ?? 0),
        ];
    }
    return $slots;
}

// Человекочитаемое описание кода ошибки PHP-загрузки
function uploadErrorMessage(int $code, string $name): string {
    switch ($code) {
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            return 'файл «' . $name . '» превышает допустимый размер (upload_max_filesize на сервере)';
        case UPLOAD_ERR_PARTIAL:
            return 'файл «' . $name . '» загружен частично — повторите попытку';
        case UPLOAD_ERR_NO_TMP_DIR:
        case UPLOAD_ERR_CANT_WRITE:
            return 'нет места для записи файла «' . $name . '» на сервере';
        case UPLOAD_ERR_NO_FILE:
            return 'файл «' . $name . '» не выбран';
        default:
            return 'ошибка загрузки файла «' . $name . '» (код ' . $code . ')';
    }
}

// Валидация одного слота (до сохранения на диск): код ошибки PHP,
// MIME-тип и расширение. Возвращает '' или текст ошибки.
function validateUploadSlot(array $slot, string $kindLabel): string {
    $name = (string)($slot['name'] ?? '');
    if (!empty($slot['dropped_by_php'])) {
        return 'файл «' . $name . '» не принят сервером: превышен лимит '
             . 'одновременной загрузки файлов (max_file_uploads = '
             . (ini_get('max_file_uploads') ?: '?') . ' в php.ini) — '
             . 'прикрепите файлы меньшими партиями или увеличьте лимит';
    }
    if (($slot['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return uploadErrorMessage((int)$slot['error'], $name);
    }
    $tmp = (string)($slot['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        return 'файл «' . $name . '» не получен сервером';
    }
    if (!isAllowedFileType($tmp)) {
        return 'недопустимый тип файла ' . $kindLabel . ': ' . $name;
    }
    if (uploadExtension($name) === '') {
        return 'недопустимое расширение файла ' . $kindLabel . ': ' . $name
             . ' (разрешены: ' . implode(', ', ALLOWED_UPLOAD_EXTENSIONS) . ')';
    }
    return '';
}

// Сохранение валидированного файла на диск + регистрация в claim_attachments.
// Возвращает [bool ok, string error, int|null attachmentId].
function saveClaimAttachment(PDO $pdo, int $claimId, string $kind, array $slot, int $userId): array {
    $kindLabel = $kind === 'response' ? 'ответа' : 'претензии';
    $name = (string)($slot['name'] ?? '');
    $ext  = uploadExtension($name);
    $prefix = $kind === 'response' ? 'resp_' : 'claim_';
    // time() одинаковый для файлов одной загрузки — вторая случайная группа
    // защищает от коллизий при мультизагрузке
    $stored = $prefix . time() . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
    if (!@move_uploaded_file((string)($slot['tmp_name'] ?? ''), UPLOAD_DIR . $stored)) {
        return [false, 'не удалось сохранить файл ' . $kindLabel . ' «' . $name . '» на сервер', null];
    }
    try {
        $stmt = $pdo->prepare("INSERT INTO claim_attachments
            (claim_id, file_kind, stored_name, original_name, file_size, uploaded_by)
            VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $claimId,
            $kind,
            $stored,
            mb_substr($name, 0, 255),
            ((int)($slot['size'] ?? 0)) > 0 ? (int)$slot['size'] : null,
            $userId > 0 ? $userId : null,
        ]);
    } catch (PDOException $e) {
        @unlink(UPLOAD_DIR . $stored);
        return [false, 'не удалось зарегистрировать файл «' . $name . '» в базе '
             . '(проверьте, что SQL-миграция step12 применена)', null];
    }
    return [true, '', (int)$pdo->lastInsertId()];
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