<?php
require 'config.php';
requireAuth();

$objectFilter = $_GET['object_id'] ?? '';

$where = '';
$params = [];
if ($objectFilter) {
    $where = 'WHERE c.object_id = ?';
    $params[] = (int)$objectFilter;
}

$stmt = $pdo->prepare("SELECT c.*, o.name as object_name, co.name as recipient_name
    FROM claims c
    LEFT JOIN objects o ON c.object_id = o.id
    LEFT JOIN companies co ON c.recipient_id = co.id
    $where
    ORDER BY c.created_at DESC");
$stmt->execute($params);
$claims = $stmt->fetchAll();

if (empty($claims)) {
    die('❌ Нет претензий для экспорта');
}

$uploadDir = UPLOAD_DIR; // файлы теперь хранятся вне корня сайта
$tmpDir = sys_get_temp_dir() . '/claim_export_' . time();
@mkdir($tmpDir, 0755, true);

$csvLines = [];
$csvLines[] = [
    'ID', 'Объект', 'Заявитель', '№ квартиры', 'Направление',
    'От кого получено', 'Дата поступления', 'Предмет претензии',
    'Текущий статус', 'Получатель (компания)', 'Направление ответа', 'Дата ответа',
    'Файл претензии', 'Файл ответа', 'Доп. файлы', 'Дней', 'Статус срока', 'Дополнительно'
];

$objectNameMap = [];
$stmt2 = $pdo->query("SELECT id, name FROM objects");
foreach ($stmt2->fetchAll() as $o) {
    $objectNameMap[$o['id']] = $o['name'];
}

foreach ($claims as $c) {
    // Формируем имя папки: clean applicant name + date + id
    $applicantClean = preg_replace('/[^a-zA-Zа-яА-ЯёЁ0-9\s\-\.]/u', '_', trim($c['applicant']));
    $applicantClean = mb_substr($applicantClean, 0, 40);
    $dateStr = $c['received_date'] ?? date('Y-m-d');
    $folderName = $applicantClean . '_' . $dateStr . '_id' . $c['id'] . '.claim';

    // Создаём папку
    $claimDir = $tmpDir . '/' . $folderName;
    @mkdir($claimDir, 0755, true);

    // ===== Копируем файлы в папку претензии (оригинальные имена, step12) =====
    // Источники: таблица claim_attachments (файлы претензии/ответа),
    // legacy-поля claim_file/response_file (записи от import.php),
    // JSON-поле additional_files (доп. документы).
    $claimFileNames = [];    // имена файлов претензии внутри папки архива
    $responseFileNames = []; // имена файлов ответа внутри папки архива
    $addFilesInFolder = [];
    $usedInFolder = [];      // дедупликация имён внутри одной папки претензии

    // Копирование файла в папку архива с уникальным оригинальным именем:
    // при совпадении «doc.pdf» → «doc_2.pdf». Возвращает имя в папке или null.
    $copyToClaimFolder = function (string $storedName, string $displayName) use ($uploadDir, $claimDir, &$usedInFolder): ?string {
        $src = $uploadDir . basename($storedName);
        if (!is_file($src)) return null;
        $name = trim($displayName) !== '' ? $displayName : basename($storedName);
        $name = preg_replace('/[\/\\:*?"<>|]+/u', '_', $name);
        if ($name === '' || $name === null) $name = basename($storedName);
        if (isset($usedInFolder[$name]) || file_exists($claimDir . '/' . $name)) {
            $pi = pathinfo($name);
            $extPart = isset($pi['extension']) && $pi['extension'] !== '' ? '.' . $pi['extension'] : '';
            $n = 2;
            while (isset($usedInFolder[$pi['filename'] . '_' . $n . $extPart])
                || file_exists($claimDir . '/' . $pi['filename'] . '_' . $n . $extPart)) {
                $n++;
            }
            $name = $pi['filename'] . '_' . $n . $extPart;
        }
        if (!@copy($src, $claimDir . '/' . $name)) return null;
        $usedInFolder[$name] = true;
        return $name;
    };

    // Вложения из таблицы (step12)
    $atts = getClaimAttachments($pdo, (int)$c['id']);
    foreach ($atts as $att) {
        $inFolder = $copyToClaimFolder((string)$att['stored_name'], (string)$att['original_name']);
        if ($inFolder === null) continue;
        if ($att['file_kind'] === 'claim') {
            $claimFileNames[] = $inFolder;
        } else {
            $responseFileNames[] = $inFolder;
        }
    }

    // Legacy-поля (файлы, ещё не перенесённые в таблицу — записи от import.php)
    $attStoredNames = array_column($atts, 'stored_name');
    if ($c['claim_file'] && !in_array($c['claim_file'], $attStoredNames, true)) {
        $inFolder = $copyToClaimFolder($c['claim_file'], basename($c['claim_file']));
        if ($inFolder !== null) $claimFileNames[] = $inFolder;
    }
    if ($c['response_file'] && !in_array($c['response_file'], $attStoredNames, true)) {
        $inFolder = $copyToClaimFolder($c['response_file'], basename($c['response_file']));
        if ($inFolder !== null) $responseFileNames[] = $inFolder;
    }

    $addFilesArr = $c['additional_files'] ? json_decode($c['additional_files'], true) : [];
    foreach ($addFilesArr as $af) {
        $inFolder = $copyToClaimFolder((string)$af, basename((string)$af));
        if ($inFolder !== null) $addFilesInFolder[] = $inFolder;
    }

    // ===== Создаём meta.ini =====
    $days = getDaysPassed($c);
    $color = getClaimStatusColor($c);
    $statusLabel = getStatusLabel($color);

    $meta = "[claim]\n";
    $meta .= "id = " . $c['id'] . "\n";
    $meta .= "object = " . ($objectNameMap[$c['object_id']] ?? '') . "\n";
    $meta .= "applicant = " . $c['applicant'] . "\n";
    $meta .= "apartment_number = " . ($c['apartment_number'] ?? '') . "\n";
    $meta .= "direction = " . $c['direction'] . "\n";
    $meta .= "received_from = " . ($c['received_from'] ?? '') . "\n";
    $meta .= "received_date = " . ($c['received_date'] ?? '') . "\n";
    $meta .= "subject = " . $c['subject'] . "\n";
    $meta .= "current_status = " . ($c['current_status'] ?? '') . "\n";
    // В INI нельзя переносить строки (parse_ini_file сломается) — сплющиваем в одну строку
    $meta .= "additional_notes = " . str_replace(["\r\n", "\n", "\r"], ' | ', (string)($c['additional_notes'] ?? '')) . "\n";
    $meta .= "recipient = " . ($c['recipient_name'] ?? '') . "\n";
    $meta .= "response_direction = " . ($c['response_direction'] ?? '') . "\n";
    $meta .= "response_date = " . ($c['response_date'] ?? '') . "\n";
    $meta .= "days_passed = " . $days . "\n";
    $meta .= "status_label = " . $statusLabel . "\n";
    // В INI нельзя переносить строки (parse_ini_file сломается) — сплющиваем в одну строку.
    // Списки файлов (step12) пишем JSON-массивом: «;» в INI — комментарий,
    // а splitAdditionalFiles()/impMetaFileList() понимают JSON.
    $meta .= "claim_file = " . (count($claimFileNames) > 1 ? json_encode($claimFileNames, JSON_UNESCAPED_UNICODE) : (string)($claimFileNames[0] ?? '')) . "\n";
    $meta .= "response_file = " . (count($responseFileNames) > 1 ? json_encode($responseFileNames, JSON_UNESCAPED_UNICODE) : (string)($responseFileNames[0] ?? '')) . "\n";
    $meta .= "additional_files = " . (count($addFilesInFolder) > 1 ? json_encode($addFilesInFolder, JSON_UNESCAPED_UNICODE) : implode(', ', $addFilesInFolder)) . "\n";
    file_put_contents($claimDir . '/meta.ini', $meta);

    // Строка для сводного CSV (csvSafe — защита от CSV-инъекции: ячейки, начинающиеся с = + - @)
    $csvLines[] = [
        $c['id'],
        $objectNameMap[$c['object_id']] ?? '',
        csvSafe($c['applicant']),
        $c['apartment_number'] ?? '',
        $c['direction'],
        csvSafe($c['received_from'] ?? ''),
        $c['received_date'] ?? '',
        csvSafe($c['subject']),
        csvSafe($c['current_status'] ?? ''),
        csvSafe($c['recipient_name'] ?? ''),
        csvSafe($c['response_direction'] ?? ''),
        $c['response_date'] ?? '',
        implode('; ', $claimFileNames),
        implode('; ', $responseFileNames),
        implode('; ', $addFilesInFolder),
        $days,
        $statusLabel,
        csvSafe($c['additional_notes'] ?? ''),
    ];
}

// ===== Создаём сводный CSV =====
$csvPath = $tmpDir . '/claims_summary.csv';
$csv = fopen($csvPath, 'w');
fwrite($csv, "\xEF\xBB\xBF");
foreach ($csvLines as $row) {
    fputcsv($csv, $row, ';');
}
fclose($csv);

// ===== Упаковываем всё в ZIP =====
$zipPath = sys_get_temp_dir() . '/claims_export_' . time() . '.zip';
$zip = new ZipArchive();
$zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);

// Добавляем summary.csv в корень
$zip->addFile($csvPath, 'claims_summary.csv');

// Добавляем все папки претензий
$dirIt = new DirectoryIterator($tmpDir);
foreach ($dirIt as $entry) {
    if (!$entry->isDot() && $entry->isDir() && str_ends_with($entry->getFilename(), '.claim')) {
        $folderName = $entry->getFilename();
        $folderPath = $entry->getPathname();
        $fileIt = new FilesystemIterator($folderPath);
        foreach ($fileIt as $file) {
            $zip->addFile($file->getPathname(), $folderName . '/' . $file->getFilename());
        }
    }
}

$zip->close();

// ===== Отдаём пользователю =====
logAction('export', 'claim', null, 'Экспорт в ZIP: ' . count($claims) . ' претензий' . ($objectFilter ? ' (объект ID ' . (int)$objectFilter . ')' : ''));

$filename = 'claims_export_' . date('Y-m-d') . '.zip';
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . filesize($zipPath));
readfile($zipPath);

// ===== Чистим временные файлы =====
unlink($csvPath);
array_map('unlink', glob("$tmpDir/*/*"));
array_map('rmdir', glob("$tmpDir/*", GLOB_ONLYDIR));
rmdir($tmpDir);
unlink($zipPath);
exit;