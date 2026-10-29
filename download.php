<?php
/**
 * download.php — защищённый шлюз отдачи прикреплённых файлов.
 *
 * Файлы хранятся ВНЕ корня сайта (константа UPLOAD_DIR в config.php),
 * поэтому напрямую по URL они недоступны. Отдача только авторизованным
 * пользователям после проверки имени файла.
 */
require 'config.php';
requireAuth();

// step12b: защита от устаревшего config.php (OPcache / неполная замена файлов)
if (!function_exists('normalizeUploadSlots')) {
    http_response_code(500);
    die('❌ config.php не обновлён (нет функций step12). Замените config.php из пакета '
        . 'и сбросьте OPcache / перезапустите PHP-FPM, затем обновите страницу.');
}

// ===== Режим 1: вложение из таблицы claim_attachments (?att=ID, step12) =====
// Имя файла на диске и оригинальное имя пользователя берутся из БД.
$attId = 0;
if (isset($_GET['att'])) {
    $attId = (int)$_GET['att'];
    if ($attId <= 0) {
        http_response_code(400);
        die('Неверный идентификатор вложения');
    }
    try {
        $stmt = $pdo->prepare("SELECT stored_name, original_name FROM claim_attachments WHERE id = ?");
        $stmt->execute([$attId]);
        $att = $stmt->fetch();
    } catch (PDOException $e) {
        $att = false; // миграция step12 не применена
    }
    if (!$att) {
        http_response_code(404);
        die('Вложение не найдено');
    }
    $fileName = basename((string)$att['stored_name']);
    $downloadName = trim((string)$att['original_name']);
    if ($downloadName === '') {
        $downloadName = $fileName; // старые вложения: оригинальное имя не сохранялось
    }
} else {
// ===== Режим 2: прямое имя файла (?file=...) — legacy-совместимость =====
    $fileName = $_GET['file'] ?? '';
    if (!is_string($fileName) || $fileName === '') {
        http_response_code(400);
        die('Файл не указан');
    }
    $fileName = basename($fileName); // отрезаем любые пути
    if ($fileName === '' || str_contains($fileName, '..') || str_starts_with($fileName, '.')) {
        http_response_code(400);
        die('Недопустимое имя файла');
    }
    $downloadName = $fileName;
}

// ===== Валидация результирующего имени на диске =====
if ($fileName === '' || str_contains($fileName, '..') || str_starts_with($fileName, '.')) {
    http_response_code(400);
    die('Недопустимое имя файла');
}

// ===== Поиск файла =====
$path = UPLOAD_DIR . $fileName;
if (!is_file($path)) {
    // Переходный период: если файл ещё не перенесён в новую папку,
    // пробуем старую (после переноса блокируется .htaccess для прямых URL,
    // но PHP по-прежнему может отдавать файлы авторизованным).
    $legacyPath = __DIR__ . '/uploads/' . $fileName;
    if (is_file($legacyPath)) {
        $path = $legacyPath;
    } else {
        http_response_code(404);
        die('Файл не найден');
    }
}

// ===== Content-Type по расширению =====
$ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
$mimeMap = [
    'pdf'  => 'application/pdf',
    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png'  => 'image/png',
    'gif'  => 'image/gif',
    'txt'  => 'text/plain',
    'doc'  => 'application/msword',
    'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'xls'  => 'application/vnd.ms-excel',
    'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'ppt'  => 'application/vnd.ms-powerpoint',
    'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
];
$mime = $mimeMap[$ext] ?? 'application/octet-stream';

// Inline открываем только безопасные типы (просмотр в браузере),
// всё остальное — принудительное скачивание.
$inline = in_array($ext, ['pdf', 'jpg', 'jpeg', 'png', 'gif', 'txt'], true);

$asciiName = preg_replace('/[^A-Za-z0-9._\-]/', '_', $downloadName);
if ($asciiName === '' || $asciiName === false) {
    $asciiName = 'file';
}

header('Content-Type: ' . $mime);
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment')
    . '; filename="' . $asciiName . '"'
    . "; filename*=UTF-8''" . rawurlencode($downloadName));
header('Content-Length: ' . filesize($path));
header('Cache-Control: private, max-age=0');

readfile($path);
exit;