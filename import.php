<?php
require 'config.php';
require_once 'helpers.php';   // resolveObject(), normalizeDate(), splitAdditionalFiles()
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfToken();
}
 $csrfToken = getCsrfToken();
requireAuth();
requireRole('admin', 'manager');

$error = '';
$message = '';

// Общее состояние импорта (передаётся в функции по ссылке)
$st = [
    'imported'         => 0,
    'skipped'          => 0,
    'filesMissing'     => 0,
    'fileLinksWarning' => false,
    'rowErrors'        => [],
    'fileWarnings'     => [],
];

// ────────────────────────────────────────────────────────────────────────────
// Локальные функции импорта (специфичны для импорта — в helpers.php их нет)
// ────────────────────────────────────────────────────────────────────────────

// Найти существующий объект по имени. Объекты НЕ создаются автоматически:
// если объекта нет — импорт прерывается с понятным сообщением.
function resolveOrCreateObject(PDO $pdo, $objectName) {
    $objectName = trim((string)$objectName);
    if ($objectName === '') {
        throw new RuntimeException('Не удалось определить объект: в CSV нет колонки «Объект», а имя файла пустое');
    }
    $id = resolveObject($pdo, $objectName);
    if ($id !== null) return (int)$id;

    $names = $pdo->query("SELECT name FROM objects ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
    throw new RuntimeException("Объект «{$objectName}» не найден на сайте. Назовите CSV-файл точно как объект"
        . ($names ? ' (есть: ' . implode(', ', $names) . ')' : '')
        . ' или сначала создайте объект в разделе «Компании и объекты»');
}

// Получить ID компании по имени (не создаёт, если нет).
// Сначала точное совпадение, затем — без учёта кавычек «»"" и регистра.
function resolveRecipient(PDO $pdo, $companyName) {
    $companyName = trim((string)$companyName);
    if ($companyName === '') return null;
    $stmt = $pdo->prepare("SELECT id FROM companies WHERE name = ?");
    $stmt->execute([$companyName]);
    $comp = $stmt->fetch();
    if ($comp) return (int)$comp['id'];

    $wanted = impNormName($companyName);
    foreach ($pdo->query("SELECT id, name FROM companies") as $c) {
        if (impNormName($c['name']) === $wanted) return (int)$c['id'];
    }
    return null;
}

// Скопировать файл в /uploads/ с оригинальным именем (не перезаписывая)
function copyImportFile($sourcePath, $uploadDir) {
    if (!file_exists($sourcePath)) return null;
    $origName = basename($sourcePath);
    $destPath = $uploadDir . $origName;
    if (!file_exists($destPath)) {
        copy($sourcePath, $destPath);
    }
    return $origName;
}

// Валидация нормализованной даты (Y-m-d) через checkdate
function validateDate($dateStr) {
    if ($dateStr === null || $dateStr === '') return true;
    $parts = explode('-', $dateStr);
    if (count($parts) === 3) {
        [$y, $m, $d] = $parts;
        if (!checkdate((int)$m, (int)$d, (int)$y)) return false;
    }
    return true;
}

// Нормализация направления → 'incoming' | 'outgoing'
function normalizeDirection($value) {
    $v = mb_strtolower(trim((string)$value));
    if ($v === '') return 'incoming';
    if (mb_strpos($v, 'исх') === 0 || mb_strpos($v, 'outg') === 0 || mb_strpos($v, 'out') === 0) return 'outgoing';
    return 'incoming';
}

// Толерантная проверка направления (совпадает с normalizeDirection)
function validateDirection($value) {
    $v = mb_strtolower(trim((string)$value));
    if ($v === '') return true;
    if (mb_strpos($v, 'исх') === 0 || mb_strpos($v, 'outg') === 0 || mb_strpos($v, 'out') === 0) return true;
    if (mb_strpos($v, 'вх') === 0 || mb_strpos($v, 'inc') === 0 || mb_strpos($v, 'in') === 0) return true;
    return false;
}

// Имя для нечёткого сравнения: без кавычек, лишних пробелов, в нижнем регистре
function impNormName($s) {
    $s = str_replace(['«', '»', '"', '“', '”', '„', "'"], ' ', (string)$s);
    $s = preg_replace('/\s+/u', ' ', $s);
    return mb_strtolower(trim($s));
}

// Имя объекта из имени файла: «Жуковский.csv» → «Жуковский»
function impObjectFromFileName($path) {
    $name = preg_replace('~^.*[/\\\\]~u', '', (string)$path);
    $name = preg_replace('/\.[^.]*$/u', '', $name);
    return trim(str_replace('_', ' ', $name));
}

// Похоже ли значение на имя файла: есть в архиве или заканчивается расширением (.pdf, .docx…)
function impLooksLikeFile($name, $fileMap) {
    if ($name === '') return false;
    if (!empty($fileMap) && isset($fileMap[$name])) return true;
    return mb_strlen($name) <= 200 && strpos($name, "\n") === false
        && (bool)preg_match('/\.(pdf|docx?|xlsx?|odt|rtf|txt|jpe?g|png|gif|bmp|tiff?|heic|zip|rar|7z|msg|eml)$/iu', $name);
}

// Обрезать длинный текст для отчёта
function impShort($s, $len = 60) {
    $s = preg_replace('/\s+/u', ' ', trim((string)$s));
    return mb_strlen($s) > $len ? mb_substr($s, 0, $len) . '…' : $s;
}

// Номер квартиры для сравнения: «кв. 101», «101», «№101» → «101»
function impNormApartment($s) {
    $s = mb_strtolower(trim((string)$s));
    $s = preg_replace('/^(кв\.?|квартира|№)\s*/u', '', $s);
    $s = trim($s);
    return preg_match('/^\d+[а-яa-z]?$/u', $s) ? ltrim($s, '0') : '';
}

// Безопасно достать значение ячейки (без warning'ов, если колонки нет)
function impCell(array $row, array $colIdx, $key) {
    if (!isset($colIdx[$key])) return '';
    return trim((string)($row[$colIdx[$key]] ?? ''));
}

// Прочитать CSV целиком: снять BOM, перевести из Windows-1251 в UTF-8 при необходимости,
// корректно разобрать ячейки с переносами строк внутри кавычек.
function impReadCsv($path) {
    $content = file_get_contents($path);
    if ($content === false) throw new RuntimeException('Не удалось прочитать CSV-файл');
    $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);
    if (!mb_check_encoding($content, 'UTF-8')) {
        $content = mb_convert_encoding($content, 'UTF-8', 'Windows-1251');
    }
    if (trim($content) === '') return null;

    $firstLine = strtok($content, "\r\n");
    $delimiter = (strpos((string)$firstLine, ';') !== false) ? ';' : ',';

    $fh = fopen('php://temp', 'r+');
    fwrite($fh, $content);
    rewind($fh);
    $rows = [];
    while (($r = fgetcsv($fh, 0, $delimiter, '"', '')) !== false) {
        $rows[] = $r;
    }
    fclose($fh);
    return $rows;
}

// Распаковать ZIP с правильными именами файлов.
// Проводник Windows пишет имена в CP866 без флага UTF-8 — их надо перекодировать,
// иначе на диске получаются «ÄÄÄ æè å¿½¿óÑßΓ...».
function impExtractZip(ZipArchive $zip, $destDir) {
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $raw = $zip->getNameIndex($i, ZipArchive::FL_ENC_RAW);
        if ($raw === false) continue;
        $name = mb_check_encoding($raw, 'UTF-8') ? $raw : mb_convert_encoding($raw, 'UTF-8', 'CP866');
        $name = str_replace('\\', '/', $name);

        // защита от путей вида ../../ и абсолютных путей
        $parts = array_filter(explode('/', $name), function ($p) { return $p !== '' && $p !== '.'; });
        if (in_array('..', $parts, true) || preg_match('~^([a-zA-Z]:|/)~', $name)) continue;
        $rel = implode('/', $parts);
        if ($rel === '') continue;

        $target = $destDir . '/' . $rel;
        if (substr($name, -1) === '/') {
            if (!is_dir($target)) mkdir($target, 0755, true);
            continue;
        }
        if (!is_dir(dirname($target))) mkdir(dirname($target), 0755, true);

        $data = $zip->getFromIndex($i);
        if ($data === false) throw new RuntimeException("Не удалось извлечь из архива «{$rel}»");
        file_put_contents($target, $data);
    }
}

// Рекурсивно удалить временную папку
function impRemoveDir($dir) {
    if (!is_dir($dir)) return;
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $file) {
        $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
    }
    @rmdir($dir);
}

// Единый маппинг колонок CSV → поля БД (регистронезависимый, с синонимами)
$fieldMap = [
    'object'            => ['объект', 'object', 'object_name'],
    'applicant'         => ['заявитель', 'applicant'],
    'apartment_number'  => ['№ квартиры', 'apartment_number', 'квартира'],
    'direction'         => ['направление', 'direction', 'исх./вх.', 'вх./исх.', 'исх/вх', 'вх/исх'],
    'received_from'     => ['от кого получено', 'received_from', 'куда поступило', 'куда поступило /направлено', 'куда поступило/направлено', 'куда поступило / направлено'],
    'received_date'     => ['дата поступления', 'received_date', 'дата'],
    'subject'           => ['предмет претензии', 'subject', 'претензия', 'предмет'],
    'current_status'    => ['текущий статус', 'current_status', 'статус', 'текущее состояние', 'текщее состояние', 'состояние'],
    'response_direction'=> ['направление ответа', 'куда направлен ответ', 'response_direction'],
    'response_date'     => ['дата ответа', 'response_date'],
    'claim_file'        => ['файл претензии', 'claim_file', 'текст претензии'],
    'response_file'     => ['файл ответа', 'response_file', 'ответ'],
    'additional_files'  => ['доп. файлы', 'доп файлы', 'additional_files', 'доп. документы', 'доп документы'],
    'recipient_id'      => ['получатель', 'компания-получатель', 'recipient', 'recipient_id', 'компания', 'company'],
];

// Поиск индексов колонок по заголовкам
function buildColumnIndex(array $headers, array $fieldMap) {
    $colIdx = [];
    foreach ($fieldMap as $fieldName => $variants) {
        foreach ($headers as $i => $h) {
            $hLower = mb_strtolower(trim((string)$h));
            foreach ($variants as $v) {
                if (mb_strtolower($v) === $hLower) {
                    $colIdx[$fieldName] = $i;
                    break 2;
                }
            }
        }
    }
    return $colIdx;
}

// Вставка одной претензии
function impInsertClaim(PDO $pdo, array $d) {
    static $stmt = null;
    if ($stmt === null) {
        $stmt = $pdo->prepare("INSERT INTO claims
            (object_id, applicant, apartment_number, direction, received_from, received_date, subject, current_status, response_direction, response_date, recipient_id, claim_file, response_file, additional_files, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    }
    $stmt->execute([
        $d['object_id'], $d['applicant'], $d['apartment_number'], $d['direction'], $d['received_from'],
        $d['received_date'], $d['subject'], $d['current_status'], $d['response_direction'], $d['response_date'],
        $d['recipient_id'], $d['claim_file'], $d['response_file'],
        !empty($d['additional_files']) ? json_encode(array_values($d['additional_files']), JSON_UNESCAPED_UNICODE) : null,
        $_SESSION['user_id'],
    ]);
}

// Импорт строк CSV. $fileMap — файлы из папки files/ архива (null для простого CSV).
function impImportCsvRows(PDO $pdo, array $rows, array $fieldMap, $uploadDir, $fileMap, array &$st, $defaultObject = '') {
    $headers = array_map(function ($h) { return trim((string)$h); }, $rows[0]);
    $colIdx = buildColumnIndex($headers, $fieldMap);

    if (!isset($colIdx['applicant']) && !isset($colIdx['subject'])) {
        return '❌ Не найдены обязательные колонки: Заявитель или Предмет претензии. Найдены колонки: ' . implode(' | ', $headers);
    }
    if ($fileMap === null && (isset($colIdx['claim_file']) || isset($colIdx['response_file']) || isset($colIdx['additional_files']))) {
        $st['fileLinksWarning'] = true;
    }

    $usedFiles = [];
    for ($i = 1; $i < count($rows); $i++) {
        $row = $rows[$i];
        $rowNum = $i + 1;

        // Полностью пустые строки (;;;;;) молча пропускаем — это хвост из Excel
        if (implode('', array_map('trim', array_map('strval', $row))) === '') continue;

        if (count($row) < 3) {
            $st['skipped']++;
            $st['rowErrors'][] = "📄 Запись {$rowNum}: менее 3 колонок — пропущена";
            continue;
        }

        $applicant = impCell($row, $colIdx, 'applicant');
        $subject   = impCell($row, $colIdx, 'subject');
        if ($applicant === '' && $subject === '') {
            $st['skipped']++;
            $st['rowErrors'][] = "📄 Запись {$rowNum}: пустые заявитель и предмет — пропущена";
            continue;
        }

        $validationErrors = [];
        $receivedDateRaw = impCell($row, $colIdx, 'received_date');
        if ($receivedDateRaw === '') {
            $validationErrors[] = 'дата поступления не указана — поставлена сегодняшняя';
        } else {
            $normalized = normalizeDate($receivedDateRaw);
            if (!$normalized || !validateDate($normalized)) {
                $validationErrors[] = "дата «{$receivedDateRaw}» невалидна";
            }
        }
        $directionRaw = impCell($row, $colIdx, 'direction');
        if ($directionRaw !== '' && !validateDirection($directionRaw)) {
            $validationErrors[] = "направление «{$directionRaw}» не распознано (будет «Входящие»)";
        }

        $companyName = impCell($row, $colIdx, 'recipient_id');
        $recipientId = resolveRecipient($pdo, $companyName);
        if ($companyName !== '' && $recipientId === null) {
            $validationErrors[] = "компания-получатель «{$companyName}» не найдена в справочнике — получатель не указан";
        }

        $cf = impCell($row, $colIdx, 'claim_file');
        $rf = impCell($row, $colIdx, 'response_file');
        $af = impCell($row, $colIdx, 'additional_files');

        // В колонках файлов часто пишут обычный текст («Решено не отвечать…»).
        // Файлом считаем только то, что похоже на имя файла или лежит в архиве.
        $claimFileName = null;
        $responseFileName = null;
        foreach (['claim_file' => [$cf, 'Текст претензии'], 'response_file' => [$rf, 'Ответ']] as $key => [$val, $colName]) {
            if ($val === '') continue;
            $fn = basename(str_replace('\\', '/', $val));
            if (impLooksLikeFile($fn, $fileMap)) {
                if ($key === 'claim_file') $claimFileName = $fn; else $responseFileName = $fn;
                if (!isset($fileMap[$fn]) && !file_exists($uploadDir . $fn)) {
                    $st['filesMissing']++;
                    $st['fileWarnings'][] = "📄 Запись {$rowNum}: файл «{$fn}» (колонка «{$colName}») не найден";
                }
            } else {
                $validationErrors[] = "в колонке «{$colName}» текст, а не файл — не перенесён: «" . impShort($val) . "»";
            }
        }

        $additionalFileNames = [];
        if ($af !== '') {
            if (!empty($fileMap)) {
                foreach (array_keys($fileMap) as $known) {
                    if ($known !== '' && mb_strpos($af, $known) !== false) $additionalFileNames[] = $known;
                }
            }
            if (empty($additionalFileNames)) {
                foreach (splitAdditionalFiles($af) as $p) {
                    $p = trim(basename(str_replace('\\', '/', trim($p))));
                    if ($p === '' || !impLooksLikeFile($p, $fileMap)) continue;
                    $additionalFileNames[] = $p;
                    if (!isset($fileMap[$p]) && !file_exists($uploadDir . $p)) {
                        $st['filesMissing']++;
                        $st['fileWarnings'][] = "📄 Запись {$rowNum}: доп. файл «{$p}» не найден";
                    }
                }
            }
            if (empty($additionalFileNames)) {
                $validationErrors[] = "в колонке «Доп. документы» текст, а не файл — не перенесён: «" . impShort($af) . "»";
            }
        }

        // Автопривязка файлов из архива по префиксу имени до первого «_»:
        // «101_Претензия.pdf» → кв. 101, «ООО СК Жиливестстрой_Отзыв.pdf» → получатель/заявитель.
        // «…претензи…» → файл претензии, «…ответ…» → файл ответа, остальное → доп. файлы.
        if (!empty($fileMap)) {
            $apartment = impCell($row, $colIdx, 'apartment_number');
            $keys = array_filter([
                impNormApartment($apartment),
                impNormName($companyName),
                impNormName($applicant),
            ], 'strlen');
            foreach (array_keys($fileMap) as $known) {
                $pos = mb_strpos($known, '_');
                if ($pos === false) continue;
                $prefix = mb_substr($known, 0, $pos);
                if (!in_array(impNormName($prefix), $keys, true) && !in_array(impNormApartment($prefix), $keys, true)) continue;
                if ($known === $claimFileName || $known === $responseFileName || in_array($known, $additionalFileNames, true)) continue;

                $rest = mb_strtolower(mb_substr($known, $pos + 1));
                if (!$claimFileName && mb_strpos($rest, 'претензи') !== false) {
                    $claimFileName = $known;
                } elseif (!$responseFileName && mb_strpos($rest, 'ответ') !== false) {
                    $responseFileName = $known;
                } else {
                    $additionalFileNames[] = $known;
                }
            }
        }
        foreach (array_merge([$claimFileName, $responseFileName], $additionalFileNames) as $fn) {
            if ($fn) $usedFiles[$fn] = true;
        }

        $status = impCell($row, $colIdx, 'current_status');

        impInsertClaim($pdo, [
            'object_id'          => resolveOrCreateObject($pdo, impCell($row, $colIdx, 'object') !== '' ? impCell($row, $colIdx, 'object') : $defaultObject),
            'applicant'          => $applicant,
            'apartment_number'   => impCell($row, $colIdx, 'apartment_number') ?: null,
            'direction'          => normalizeDirection($directionRaw),
            'received_from'      => impCell($row, $colIdx, 'received_from') ?: null,
            'received_date'      => normalizeDate($receivedDateRaw) ?: date('Y-m-d'),
            'subject'            => $subject,
            'current_status'     => $status !== '' ? $status : 'Импортировано',
            'response_direction' => impCell($row, $colIdx, 'response_direction') ?: null,
            'response_date'      => normalizeDate(impCell($row, $colIdx, 'response_date')) ?: null,
            'recipient_id'       => $recipientId,
            'claim_file'         => $claimFileName,
            'response_file'      => $responseFileName,
            'additional_files'   => $additionalFileNames,
        ]);
        $st['imported']++;

        foreach ($validationErrors as $ve) {
            $st['rowErrors'][] = "📄 Запись {$rowNum} (импортирована): ⚠️ {$ve}";
        }
    }

    if (!empty($fileMap)) {
        foreach (array_keys($fileMap) as $fn) {
            if (!isset($usedFiles[$fn])) {
                $st['fileWarnings'][] = "📁 Файл «{$fn}» скопирован в uploads, но не привязан ни к одной претензии";
            }
        }
    }
    return '';
}

// ────────────────────────────────────────────────────────────────────────────
//  ОБРАБОТКА ЗАГРУЗКИ
// ────────────────────────────────────────────────────────────────────────────

$uploadErrors = [
    UPLOAD_ERR_INI_SIZE   => 'файл больше, чем разрешено в php.ini (upload_max_filesize)',
    UPLOAD_ERR_FORM_SIZE  => 'файл больше, чем разрешено формой',
    UPLOAD_ERR_PARTIAL    => 'файл загрузился не полностью',
    UPLOAD_ERR_NO_FILE    => 'файл не выбран',
    UPLOAD_ERR_NO_TMP_DIR => 'на сервере нет временной папки',
    UPLOAD_ERR_CANT_WRITE => 'не удалось записать файл на диск',
    UPLOAD_ERR_EXTENSION  => 'загрузку остановило расширение PHP',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_FILES) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    $error = '❌ Файл слишком большой: превышен post_max_size в php.ini';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['import_file']) && $_FILES['import_file']['error'] !== UPLOAD_ERR_OK) {
    $error = '❌ Ошибка загрузки: ' . ($uploadErrors[$_FILES['import_file']['error']] ?? 'код ' . $_FILES['import_file']['error']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['import_file']) && $_FILES['import_file']['error'] === UPLOAD_ERR_OK) {
    $fileTmp  = $_FILES['import_file']['tmp_name'];
    $fileName = $_FILES['import_file']['name'];
    $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

    $uploadDir = __DIR__ . '/uploads/';
    $tmpDir = sys_get_temp_dir() . '/claim_import_' . uniqid();

    try {
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
        $pdo->beginTransaction();

        // ===============================================================
        //  ФОРМАТ 1: ZIP-архив
        // ===============================================================
        if ($ext === 'zip') {
            if (!class_exists('ZipArchive')) {
                throw new RuntimeException('В PHP не включено расширение zip (ZipArchive). Включите php_zip в настройках PHP / OSPanel');
            }
            $zip = new ZipArchive();
            if ($zip->open($fileTmp) !== true) {
                $error = '❌ Не удалось открыть ZIP-архив';
            } else {
                mkdir($tmpDir, 0755, true);
                impExtractZip($zip, $tmpDir);
                $zip->close();

                $claimDirs = glob($tmpDir . '/*.claim', GLOB_ONLYDIR) ?: [];

                // ── 1A: папки .claim ──
                if (!empty($claimDirs)) {
                    foreach ($claimDirs as $claimDir) {
                        $dirName = basename($claimDir);
                        $metaFile = $claimDir . '/meta.ini';
                        if (!file_exists($metaFile)) {
                            $st['skipped']++;
                            $st['rowErrors'][] = "📁 Папка «{$dirName}»: отсутствует meta.ini";
                            continue;
                        }

                        $meta = @parse_ini_file($metaFile, false, INI_SCANNER_RAW);
                        if (!$meta) {
                            $st['skipped']++;
                            $st['rowErrors'][] = "📁 Папка «{$dirName}»: не удалось прочитать meta.ini";
                            continue;
                        }

                        $applicant = trim((string)($meta['applicant'] ?? ''));
                        $subject   = trim((string)($meta['subject'] ?? ''));
                        if ($applicant === '' && $subject === '') {
                            $st['skipped']++;
                            $st['rowErrors'][] = "📁 Папка «{$dirName}»: не указаны заявитель и предмет претензии";
                            continue;
                        }

                        $validationErrors = [];
                        if (!empty($meta['received_date'])) {
                            $normalized = normalizeDate($meta['received_date']);
                            if (!$normalized || !validateDate($normalized)) {
                                $validationErrors[] = "дата «{$meta['received_date']}» невалидна";
                            }
                        }
                        if (!empty($meta['direction']) && !validateDirection($meta['direction'])) {
                            $validationErrors[] = "направление «{$meta['direction']}» не распознано (будет «Входящие»)";
                        }

                        $claimFileName = null;
                        $responseFileName = null;
                        $additionalFileNames = [];

                        foreach (['claim_file' => 'файл претензии', 'response_file' => 'файл ответа'] as $key => $label) {
                            if (empty($meta[$key])) continue;
                            if (file_exists($claimDir . '/' . $meta[$key])) {
                                $copied = copyImportFile($claimDir . '/' . $meta[$key], $uploadDir);
                                if ($key === 'claim_file') $claimFileName = $copied; else $responseFileName = $copied;
                            } else {
                                $st['fileWarnings'][] = "📁 Папка «{$dirName}»: {$label} «{$meta[$key]}» не найден";
                                $st['filesMissing']++;
                            }
                        }

                        if (!empty($meta['additional_files'])) {
                            foreach (splitAdditionalFiles($meta['additional_files']) as $addEntry) {
                                $addEntry = trim($addEntry);
                                if ($addEntry === '') continue;
                                if (file_exists($claimDir . '/' . $addEntry)) {
                                    $copied = copyImportFile($claimDir . '/' . $addEntry, $uploadDir);
                                    if ($copied) $additionalFileNames[] = $copied;
                                } else {
                                    $st['fileWarnings'][] = "📁 Папка «{$dirName}»: доп. файл «{$addEntry}» не найден";
                                    $st['filesMissing']++;
                                }
                            }
                        }

                        impInsertClaim($pdo, [
                            'object_id'          => resolveOrCreateObject($pdo, $meta['object'] ?? ''),
                            'applicant'          => $applicant,
                            'apartment_number'   => ($meta['apartment_number'] ?? '') ?: null,
                            'direction'          => normalizeDirection($meta['direction'] ?? ''),
                            'received_from'      => ($meta['received_from'] ?? '') ?: null,
                            'received_date'      => normalizeDate($meta['received_date'] ?? '') ?: date('Y-m-d'),
                            'subject'            => $subject,
                            'current_status'     => ($meta['current_status'] ?? '') ?: 'Импортировано',
                            'response_direction' => ($meta['response_direction'] ?? '') ?: null,
                            'response_date'      => normalizeDate($meta['response_date'] ?? '') ?: null,
                            'recipient_id'       => resolveRecipient($pdo, $meta['recipient'] ?? $meta['recipient_id'] ?? $meta['company'] ?? ''),
                            'claim_file'         => $claimFileName,
                            'response_file'      => $responseFileName,
                            'additional_files'   => $additionalFileNames,
                        ]);
                        $st['imported']++;

                        foreach ($validationErrors as $ve) {
                            $st['rowErrors'][] = "📁 Папка «{$dirName}» (импортирована): ⚠️ {$ve}";
                        }
                    }
                }

                // ── 1B: ZIP с CSV + папкой files/ ──
                else {
                    $csvFiles = glob($tmpDir . '/*.csv') ?: [];
                    if (empty($csvFiles)) {
                        $error = '❌ В архиве не найдено ни папок .claim, ни CSV-файлов (CSV должен лежать в корне архива)';
                    } else {
                        $rows = impReadCsv($csvFiles[0]);
                        if (empty($rows)) {
                            $error = '❌ CSV-файл пуст';
                        } else {
                            // Копируем все файлы из files/ в /uploads/
                            $archiveFilesDir = $tmpDir . '/files/';
                            $fileMap = [];
                            if (is_dir($archiveFilesDir)) {
                                foreach (new FilesystemIterator($archiveFilesDir) as $file) {
                                    if (!$file->isFile()) continue;
                                    $fname = $file->getFilename();
                                    if (!file_exists($uploadDir . $fname)) {
                                        copy($file->getPathname(), $uploadDir . $fname);
                                    }
                                    $fileMap[$fname] = true;
                                }
                            }
                            // Объект по умолчанию = имя CSV-файла (лист Excel), напр. «Жуковский.csv» → «Жуковский»
                            $error = impImportCsvRows($pdo, $rows, $fieldMap, $uploadDir, $fileMap, $st, impObjectFromFileName($csvFiles[0]));
                        }
                    }
                }
            }
        }

        // ===============================================================
        //  ФОРМАТ 2: Простой CSV-файл
        // ===============================================================
        elseif ($ext === 'csv') {
            $rows = impReadCsv($fileTmp);
            if (empty($rows)) {
                $error = '❌ CSV-файл пуст';
            } else {
                $error = impImportCsvRows($pdo, $rows, $fieldMap, $uploadDir, null, $st, impObjectFromFileName($fileName));
            }
        } else {
            $error = '❌ Поддерживаются только файлы .zip и .csv';
        }

        if ($error === '') {
            $pdo->commit();
        } else {
            $pdo->rollBack();
            $st['imported'] = 0;
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[import.php] ' . $e);
        $st['imported'] = 0;
        $error = '❌ Импорт прерван, в базу ничего не записано. Причина: '
            . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8')
            . ' <small class="text-muted">(' . htmlspecialchars(basename($e->getFile()), ENT_QUOTES, 'UTF-8') . ':' . $e->getLine() . ')</small>';
    } finally {
        impRemoveDir($tmpDir);
    }

    // ===== Итоговый отчёт =====
    $imported      = $st['imported'];
    $skipped       = $st['skipped'];
    $filesMissing  = $st['filesMissing'];
    $rowErrors     = $st['rowErrors'];
    $fileWarnings  = $st['fileWarnings'];
    $totalErrors   = count($rowErrors);
    $totalWarnings = count($fileWarnings);
    $h = function ($s) { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); };

    if (empty($error)) {
        if ($imported > 0 || $skipped > 0) {
            $message = "<div class='import-report'>";
            $message .= "<div class='d-flex align-items-center gap-3 mb-2 flex-wrap'>";
            $message .= "<span class='badge bg-success fs-6 px-3 py-2'>✅ Импортировано: <strong>{$imported}</strong></span>";
            if ($skipped > 0) {
                $message .= "<span class='badge bg-warning text-dark fs-6 px-3 py-2'>⚠️ Пропущено: <strong>{$skipped}</strong></span>";
            }
            if ($totalErrors > 0) {
                $message .= "<span class='badge bg-danger fs-6 px-3 py-2'>❌ Замечаний: <strong>{$totalErrors}</strong></span>";
            }
            if ($filesMissing > 0) {
                $message .= "<span class='badge bg-secondary fs-6 px-3 py-2'>📁 Файлов не найдено: <strong>{$filesMissing}</strong></span>";
            }
            $message .= "</div>";

            if ($totalErrors > 0) {
                $message .= "<div class='mt-3'>";
                $message .= "<a class='btn btn-sm btn-outline-danger' data-bs-toggle='collapse' href='#importErrors' role='button'>";
                $message .= "<i class='bi bi-exclamation-triangle'></i> Показать детали по строкам ({$totalErrors})";
                $message .= "</a>";
                $message .= "<div class='collapse mt-2' id='importErrors'>";
                $message .= "<div class='card card-body bg-light p-2' style='max-height:300px; overflow-y:auto;'>";
                $message .= "<ul class='list-unstyled mb-0 small'>";
                foreach ($rowErrors as $re) {
                    $message .= "<li class='py-1 border-bottom border-secondary border-opacity-10'>" . $h($re) . "</li>";
                }
                $message .= "</ul></div></div></div>";
            }

            if ($totalWarnings > 0) {
                $message .= "<div class='mt-2'>";
                $message .= "<a class='btn btn-sm btn-outline-warning' data-bs-toggle='collapse' href='#fileWarnings' role='button'>";
                $message .= "<i class='bi bi-file-earmark-excel'></i> Показать предупреждения о файлах ({$totalWarnings})";
                $message .= "</a>";
                $message .= "<div class='collapse mt-2' id='fileWarnings'>";
                $message .= "<div class='card card-body bg-light p-2' style='max-height:200px; overflow-y:auto;'>";
                $message .= "<ul class='list-unstyled mb-0 small'>";
                foreach ($fileWarnings as $fw) {
                    $message .= "<li class='py-1 border-bottom border-secondary border-opacity-10'>" . $h($fw) . "</li>";
                }
                $message .= "</ul></div></div></div>";
            }

            if ($st['fileLinksWarning']) {
                $message .= "<div class='alert alert-warning mt-2 mb-0 py-2'>ℹ️ В CSV есть колонки файлов, но при импорте простого CSV файлы не копируются. Используйте ZIP с папкой <code>files/</code></div>";
            }

            $message .= "</div>";

            if ($imported > 0) {
                try {
                    logAction('import', 'claim', null, "Импорт претензий: {$imported} шт., пропущено: {$skipped}, ошибок: {$totalErrors}");
                } catch (Throwable $e) {
                    error_log('[import.php] logAction: ' . $e);
                    $message .= "<div class='alert alert-warning mt-2 mb-0 py-2'>⚠️ Претензии импортированы, но запись в журнал действий не удалась: "
                        . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . " (" . htmlspecialchars(basename($e->getFile()), ENT_QUOTES, 'UTF-8') . ":" . $e->getLine() . ")</div>";
                }
            }
        } else {
            $error = '❌ Не найдено претензий для импорта';
        }
    }
}

$user = currentUser();

// ===== Переменные для шаблона =====
$pageTitle = 'Импорт претензий — Реестр претензий';
$currentPage = 'import';
$showUsersLink = false;
$isAdmin = false;
$extraHead = '';

require 'templates/header.php';
?>
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h3 class="fw-bold mb-0"><i class="bi bi-upload text-primary"></i> Импорт претензий</h3>
                <a href="index.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> Назад</a>
            </div>

            <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show"><?= $error ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
            <?php endif; ?>
            <?php if ($message): ?>
                <div class="alert alert-success alert-dismissible fade show"><?= $message ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
            <?php endif; ?>

            <div class="card shadow-sm border-0">
                <div class="card-body p-4">
                    <form method="POST" enctype="multipart/form-data" class="row g-3 align-items-end">
                        <div class="col-md-8">
                            <label class="form-label fw-semibold">📁 Файл для импорта</label>
                            <input type="file" name="import_file" class="form-control" accept=".csv,.zip" required>
                            <small class="text-muted mt-1 d-block">ZIP (папки .claim), ZIP (CSV + files/) или простой CSV. Кодировка UTF-8 или Windows-1251, даты ДД.ММ.ГГГГ — допустимы</small>
                        </div>
                        <div class="col-md-4">
                            <button type="submit" class="btn btn-primary btn-lg w-100">
                                <i class="bi bi-upload"></i> Импортировать
                            </button>
                        </div>
                    </form>

                    <hr class="my-4">

                    <h6 class="fw-bold">📋 Поддерживаемые форматы импорта</h6>
                    <div class="row g-3 mt-2">
                        <div class="col-md-4">
                            <div class="card border-success h-100">
                                <div class="card-body text-center py-3">
                                    <i class="bi bi-folder2-open text-success fs-2 d-block mb-2"></i>
                                    <h6 class="fw-bold">📁 Новый формат</h6>
                                    <small class="text-muted">ZIP с папками <code>.claim</code><br>
                                    В каждой: <code>meta.ini</code> + файлы</small>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="card border-warning h-100">
                                <div class="card-body text-center py-3">
                                    <i class="bi bi-archive text-warning fs-2 d-block mb-2"></i>
                                    <h6 class="fw-bold">📦 Старый формат</h6>
                                    <small class="text-muted">ZIP с CSV + папка <code>files/</code></small>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="card border-info h-100">
                                <div class="card-body text-center py-3">
                                    <i class="bi bi-filetype-csv text-info fs-2 d-block mb-2"></i>
                                    <h6 class="fw-bold">📄 Только CSV</h6>
                                    <small class="text-muted">Простой CSV без вложенных файлов</small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
<?php
require 'templates/footer.php';