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
    'Файл претензии', 'Файл ответа', 'Доп. файлы', 'Дней', 'Статус срока'
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

    // ===== Копируем файлы в папку претензии (оригинальные имена) =====
    $claimFileInFolder = '';
    $responseFileInFolder = '';
    $addFilesInFolder = [];

    if ($c['claim_file'] && file_exists($uploadDir . $c['claim_file'])) {
        $claimFileInFolder = $c['claim_file'];  // оригинальное имя без префикса
        copy($uploadDir . $c['claim_file'], $claimDir . '/' . $claimFileInFolder);
    }

    if ($c['response_file'] && file_exists($uploadDir . $c['response_file'])) {
        $responseFileInFolder = $c['response_file'];  // оригинальное имя без префикса
        copy($uploadDir . $c['response_file'], $claimDir . '/' . $responseFileInFolder);
    }

    $addFilesArr = $c['additional_files'] ? json_decode($c['additional_files'], true) : [];
    foreach ($addFilesArr as $af) {
        if (file_exists($uploadDir . $af)) {
            $addFilesInFolder[] = $af;  // оригинальное имя, без префикса add_
            copy($uploadDir . $af, $claimDir . '/' . $af);
        }
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
    $meta .= "recipient = " . ($c['recipient_name'] ?? '') . "\n";
    $meta .= "response_direction = " . ($c['response_direction'] ?? '') . "\n";
    $meta .= "response_date = " . ($c['response_date'] ?? '') . "\n";
    $meta .= "days_passed = " . $days . "\n";
    $meta .= "status_label = " . $statusLabel . "\n";
    $meta .= "claim_file = " . $claimFileInFolder . "\n";
    $meta .= "response_file = " . $responseFileInFolder . "\n";
    $meta .= "additional_files = " . implode(', ', $addFilesInFolder) . "\n";
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
        $claimFileInFolder,
        $responseFileInFolder,
        implode('; ', $addFilesInFolder),
        $days,
        $statusLabel,
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