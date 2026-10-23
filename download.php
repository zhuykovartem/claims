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

// ===== Валидация имени файла =====
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

$asciiName = preg_replace('/[^A-Za-z0-9._\-]/', '_', $fileName);

header('Content-Type: ' . $mime);
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment')
    . '; filename="' . $asciiName . '"'
    . "; filename*=UTF-8''" . rawurlencode($fileName));
header('Content-Length: ' . filesize($path));
header('Cache-Control: private, max-age=0');

readfile($path);
exit;