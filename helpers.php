<?php

/**
 * helpers.php — общие вспомогательные функции для проекта «Реестр претензий»
 * Вынесены из import.php, export.php и других файлов для переиспользования.
 */

// ────────────────────────────────────────────────────────────────────────────
// 1. КОНСТАНТА: маппинг колонок CSV → поля БД
// ────────────────────────────────────────────────────────────────────────────

const CLAIM_FIELD_MAP = [
    // Русские заголовки (основные)
    'Объект'               => 'object_name',
    'Заявитель'            => 'applicant',
    '№ квартиры'           => 'apartment_number',
    'Номер квартиры'       => 'apartment_number',
    'Квартира'             => 'apartment_number',
    'Направление'          => 'direction',
    'От кого получено'     => 'received_from',
    'Дата поступления'     => 'received_date',
    'Дата'                 => 'received_date',
    'Предмет претензии'    => 'subject',
    'Суть'                 => 'subject',
    'Описание'             => 'subject',
    'Текущий статус'       => 'current_status',
    'Статус'               => 'current_status',
    'Получатель'           => 'recipient_name',
    'Кому направлено'      => 'recipient_name',
    'Направление ответа'   => 'response_direction',
    'Куда направлен ответ' => 'response_direction',  // синоним
    'Дата ответа'          => 'response_date',
    'Ответ дата'           => 'response_date',
    'Файл претензии'       => 'claim_file',
    'Файл'                 => 'claim_file',
    'Файл ответа'          => 'response_file',
    'Ответ файл'           => 'response_file',
    'Доп. файлы'           => 'additional_files',
    'Дополнительные файлы' => 'additional_files',
    'Доп файлы'            => 'additional_files',

    // Английские заголовки (на всякий случай)
    'object'               => 'object_name',
    'applicant'            => 'applicant',
    'apartment'            => 'apartment_number',
    'direction'            => 'direction',
    'received_from'        => 'received_from',
    'received_date'        => 'received_date',
    'subject'              => 'subject',
    'status'               => 'current_status',
    'recipient'            => 'recipient_name',
    'response_direction'   => 'response_direction',
    'response_date'        => 'response_date',
    'claim_file'           => 'claim_file',
    'response_file'        => 'response_file',
    'additional_files'     => 'additional_files',
];

// ────────────────────────────────────────────────────────────────────────────
// 2. resolveObject() — найти ID объекта по названию
// ────────────────────────────────────────────────────────────────────────────

/**
 * Находит ID объекта строительства по его названию.
 * Если объект не найден — возвращает null.
 *
 * @param PDO    $pdo
 * @param string $name Название объекта
 * @return int|null
 */
function resolveObject(PDO $pdo, string $name): ?int
{
    $name = trim($name);
    if ($name === '') {
        return null;
    }

    // Точное совпадение
    $stmt = $pdo->prepare("SELECT id FROM objects WHERE name = ?");
    $stmt->execute([$name]);
    $id = $stmt->fetchColumn();
    if ($id !== false) {
        return (int)$id;
    }

    // Совпадение по части названия (если точного нет)
    $stmt = $pdo->prepare("SELECT id FROM objects WHERE name LIKE ? LIMIT 1");
    $stmt->execute(['%' . $name . '%']);
    $id = $stmt->fetchColumn();

    return $id !== false ? (int)$id : null;
}

// ────────────────────────────────────────────────────────────────────────────
// 3. normalizeDate() — привести дату к формату Y-m-d
// ────────────────────────────────────────────────────────────────────────────

/**
 * Приводит дату из различных форматов к YYYY-MM-DD.
 * Поддерживает: дд.мм.гггг, дд/мм/гггг, дд-мм-гггг, YYYY-MM-DD, YYYY/MM/DD
 *
 * @param string|null $date
 * @return string|null Вернёт null, если дата не распознана
 */
function normalizeDate(?string $date): ?string
{
    if (empty($date) || trim($date) === '') {
        return null;
    }

    $date = trim($date);

    // Уже ISO?
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return $date;
    }

    // Попробуем распарсить популярные русские форматы
    $formats = [
        'd.m.Y', 'd.m.y',      // 31.12.2024, 31.12.24
        'd/m/Y', 'd/m/y',      // 31/12/2024
        'd-m-Y', 'd-m-y',      // 31-12-2024
        'Y/m/d',                // 2024/12/31
        'Y.m.d',                // 2024.12.31
        'j.m.Y', 'j.n.Y',      // без ведущего нуля
        'j F Y',               // 31 декабря 2024
    ];

    foreach ($formats as $fmt) {
        $dt = \DateTime::createFromFormat($fmt, $date);
        if ($dt !== false) {
            return $dt->format('Y-m-d');
        }
    }

    // Последняя попытка — strtotime
    $ts = strtotime($date);
    if ($ts !== false && $ts > 0) {
        return date('Y-m-d', $ts);
    }

    return null;
}

// ────────────────────────────────────────────────────────────────────────────
// 4. splitAdditionalFiles() — разобрать строку доп. файлов в массив
// ────────────────────────────────────────────────────────────────────────────

/**
 * Разделяет строку дополнительных файлов (через ; или перевод строки)
 * в плоский массив имён файлов.
 * Убирает пустые элементы и дубликаты.
 *
 * @param string|null $filesString
 * @return string[]
 */
function splitAdditionalFiles(?string $filesString): array
{
    if (empty($filesString)) {
        return [];
    }

    // Сначала пробуем JSON
    $decoded = json_decode($filesString, true);
    if (is_array($decoded)) {
        $result = [];
        foreach ($decoded as $item) {
            if (is_string($item) && trim($item) !== '') {
                $result[] = trim($item);
            }
        }
        return array_unique($result);
    }

    // Иначе — разделяем по точке с запятой, запятой или переводу строки
    $parts = preg_split('/[;,]\s*|\r\n|\n|\r/', $filesString);
    $result = [];
    foreach ($parts as $part) {
        $part = trim($part);
        if ($part !== '') {
            $result[] = $part;
        }
    }

    return array_unique($result);
}

// ────────────────────────────────────────────────────────────────────────────
// 5. ensureUtf8Lines() — привести массив строк к UTF-8 (с удалением BOM)
// ────────────────────────────────────────────────────────────────────────────

/**
 * Принимает массив строк CSV и приводит каждую к UTF-8.
 * Удаляет BOM (\xEF\xBB\xBF) из первой строки, если он есть.
 * Конвертирует из CP1251/ISO-8859-1 в UTF-8 при необходимости.
 *
 * @param array $lines
 * @return array
 */
function ensureUtf8Lines(array $lines): array
{
    // ===== Удаляем BOM из первой строки =====
    if (count($lines) > 0 && is_string($lines[0]) && str_starts_with($lines[0], "\xEF\xBB\xBF")) {
        $lines[0] = mb_substr($lines[0], 3);
    }

    // ===== Конвертируем кодировку каждой строки/поля =====
    foreach ($lines as &$line) {
        if (is_array($line)) {
            // Массив полей одной строки CSV
            foreach ($line as &$field) {
                if (is_string($field) && !mb_check_encoding($field, 'UTF-8')) {
                    $detected = mb_detect_encoding($field, ['UTF-8', 'Windows-1251', 'CP1251', 'ISO-8859-1'], true);
                    $field = mb_convert_encoding($field, 'UTF-8', $detected ?: 'Windows-1251');
                }
            }
            unset($field);
        } elseif (is_string($line)) {
            // Целая строка
            if (!mb_check_encoding($line, 'UTF-8')) {
                $detected = mb_detect_encoding($line, ['UTF-8', 'Windows-1251', 'CP1251', 'ISO-8859-1'], true);
                $line = mb_convert_encoding($line, 'UTF-8', $detected ?: 'Windows-1251');
            }
        }
    }
    unset($line);

    return $lines;
}

// ────────────────────────────────────────────────────────────────────────────
// 6. formatBytes() — вспомогательная, для красивого вывода размеров
// ────────────────────────────────────────────────────────────────────────────

function formatBytes(int $bytes, int $precision = 2): string
{
    if ($bytes <= 0) {
        return '0 B';
    }
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $exp = min(floor(log($bytes, 1024)), count($units) - 1);
    return round($bytes / (1024 ** $exp), $precision) . ' ' . $units[$exp];
}

// ────────────────────────────────────────────────────────────────────────────
// 7. safeFileName() — очистка имени файла от недопустимых символов
// ────────────────────────────────────────────────────────────────────────────

function safeFileName(string $name): string
{
    // Удаляем символы, недопустимые в именах файлов Windows/Linux
    $clean = preg_replace('/[\/\\\\:*?"<>|]+/u', '_', $name);
    // Схлопываем множественные подчёркивания
    $clean = preg_replace('/_+/u', '_', $clean);
    // Обрезаем до разумной длины (200 символов)
    return mb_substr($clean, 0, 200);
}

// ────────────────────────────────────────────────────────────────────────────
// 8. getPdo() — ленивое получение PDO (если config.php не загружен)
// ────────────────────────────────────────────────────────────────────────────

/**
 * Возвращает глобальный PDO из config.php, либо выбрасывает исключение.
 * Удобно для использования в helpers вне контекста config.php.
 *
 * @return PDO
 * @throws RuntimeException
 */
function getPdo(): PDO
{
    global $pdo;
    if (!isset($pdo) || !($pdo instanceof PDO)) {
        throw new RuntimeException('PDO не инициализирован. Подключите config.php.');
    }
    return $pdo;
}