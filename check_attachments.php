<?php
require 'config.php';
requireAuth();
requireRole('admin'); // диагностика схемы БД и сервера — только администратору

// ============================================================
// step12c: страница диагностики хранилища вложений.
// Показывает ТОЛЬКО схему и счётчики (без содержимого документов):
//   1) настройки PHP веб-сервера (upload_max_filesize, post_max_size,
//      max_file_uploads — именно те, что действуют при загрузке из браузера);
//   2) папку UPLOAD_DIR (существует, доступна для записи, свободное место);
//   3) таблицу claim_attachments: существует ли, реальные индексы
//      (ловим UNIQUE по (claim_id, file_kind) — причина «сохраняется один файл»);
//   4) вложения конкретной претензии (?claim_id=N) + состояние legacy-полей;
//   5) ВЕРДИКТ: список проблем с готовым SQL для исправления.
// Страница только читает (SELECT / SHOW), данные не изменяет.
// ============================================================

$claimId = (int)($_GET['claim_id'] ?? 0);

// ---------- PHP: загрузка файлов ----------
$phpUpload = [
    'file_uploads'          => ini_get('file_uploads'),
    'upload_max_filesize'   => ini_get('upload_max_filesize'),
    'post_max_size'         => ini_get('post_max_size'),
    'max_file_uploads'      => ini_get('max_file_uploads'),
    'upload_tmp_dir'        => ini_get('upload_tmp_dir') ?: '(системный tmp)',
    'memory_limit'          => ini_get('memory_limit'),
    'max_execution_time'    => ini_get('max_execution_time') . ' сек',
];
$sapiPhp     = PHP_SAPI;
$loadedIni   = php_ini_loaded_file() ?: '(не определён)';
$iniScanDir  = php_ini_scanned_files() ?: '';

// ---------- Папка UPLOAD_DIR ----------
$dirPath     = UPLOAD_DIR;
$dirExists   = is_dir($dirPath);
$dirWritable = $dirExists && is_writable($dirPath);
$dirFree     = $dirExists ? @disk_free_space($dirPath) : null;
$probeOk     = false;
$probeErr    = '';
if ($dirWritable) {
    $probe = $dirPath . 'probe_' . bin2hex(random_bytes(4)) . '.tmp';
    if (@file_put_contents($probe, 'step12c probe') !== false) {
        $probeOk = true;
        @unlink($probe);
    } else {
        $probeErr = 'пробный файл записать не удалось (права/SELinux/appArmor?)';
    }
}

// ---------- Таблица claim_attachments ----------
$tableExists = false;
$createSql   = '';
$idxRows     = [];
$totalRows   = 0;
$kindCounts  = [];   // kind => n
$multiClaims = [];   // [claim_id, cnt] — претензии с несколькими файлами
$tblErr      = '';
try {
    $pdo->query("SELECT 1 FROM claim_attachments LIMIT 1");
    $tableExists = true;
} catch (PDOException $e) {
    $tblErr = trim((string)($e->errorInfo[2] ?? $e->getMessage()));
}
if ($tableExists) {
    try {
        $row = $pdo->query("SHOW CREATE TABLE claim_attachments")->fetch(PDO::FETCH_ASSOC);
        $createSql = (string)($row['Create Table'] ?? '');
    } catch (PDOException $e) { /* не критично */ }
    try {
        $idxRows = $pdo->query("SHOW INDEX FROM claim_attachments")->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) { /* не критично */ }
    try {
        foreach ($pdo->query("SELECT file_kind, COUNT(*) AS n FROM claim_attachments GROUP BY file_kind")->fetchAll() as $r) {
            $kindCounts[(string)$r['file_kind']] = (int)$r['n'];
        }
        $totalRows = array_sum($kindCounts);
        $multiClaims = $pdo->query(
            "SELECT claim_id, COUNT(*) AS cnt FROM claim_attachments
             GROUP BY claim_id HAVING cnt > 1 ORDER BY cnt DESC LIMIT 5"
        )->fetchAll();
    } catch (PDOException $e) { /* не критично */ }
}

// ---------- По конкретной претензии ----------
$claimRows  = [];
$legacy     = null;
$claimInfo  = null;
if ($claimId > 0) {
    try {
        $st = $pdo->prepare("SELECT id, subject, claim_file, response_file, additional_files FROM claims WHERE id = ?");
        $st->execute([$claimId]);
        $claimInfo = $st->fetch();
    } catch (PDOException $e) { $claimInfo = null; }
    if ($claimInfo) {
        try {
            $st = $pdo->prepare("SELECT id, file_kind, stored_name, original_name, file_size, uploaded_at
                                 FROM claim_attachments WHERE claim_id = ? ORDER BY id");
            $st->execute([$claimId]);
            $claimRows = $st->fetchAll();
        } catch (PDOException $e) { $claimRows = []; }
    }
}

// ---------- Вердикт ----------
$problems = [];   // [['title'=>, 'detail'=>, 'fix'=>]]
if (!$tableExists) {
    $problems[] = [
        'title'  => 'Таблица claim_attachments не существует',
        'detail' => 'Каждый файл сначала сохраняется на диск, потом регистрируется в БД. '
                  . 'Без таблицы регистрация падает, файл удаляется, и вложение «исчезает». '
                  . 'Ошибка СУБД: ' . ($tblErr !== '' ? $tblErr : '—'),
        'fix'    => 'Выполните на базе (mysql или phpMyAdmin) файл из пакета step12:\n'
                  . '  migrations/2026-10-28_step12_claim_attachments.sql\n'
                  . 'Он создаёт таблицу и переносит существующие одиночные файлы.',
    ];
} else {
    // ищем ограничивающий UNIQUE-индекс (та же логика, что в баннере)
    $colsByIndex = [];
    foreach ($idxRows as $row) {
        $colsByIndex[(string)$row['Key_name']][] = (string)$row['Column_name'];
    }
    $seen = [];
    foreach ($idxRows as $row) {
        if ((int)($row['Non_unique'] ?? 1) !== 0) continue;
        $idxName = (string)$row['Key_name'];
        if ($idxName === 'PRIMARY' || isset($seen[$idxName])) continue;
        $cols = array_values(array_unique($colsByIndex[$idxName] ?? []));
        sort($cols);
        if (!array_diff($cols, ['claim_id', 'file_kind']) && !empty($cols)) {
            $seen[$idxName] = true;
            $problems[] = [
                'title'  => 'UNIQUE-индекс «' . $idxName . '» по (' . implode(', ', $cols) . ')',
                'detail' => 'Такой индекс разрешает только ОДНУ строку на пару «претензия + тип файла». '
                          . 'Первый файл сохраняется, все следующие отвергаются СУБД как дубликаты — '
                          . 'ровно ваш симптом «отображается один блок».',
                'fix'    => "ALTER TABLE claim_attachments DROP INDEX `" . $idxName . "`,\n"
                          . "  ADD INDEX idx_att_claim (claim_id, file_kind);",
            ];
        }
    }
}
if (!$dirExists) {
    $problems[] = [
        'title'  => 'Папка UPLOAD_DIR не существует',
        'detail' => 'Путь: ' . $dirPath . '. Файлы некуда сохранять.',
        'fix'    => 'Создайте папку и дайте права процессу PHP (www-data) на запись:\n'
                  . '  mkdir -p ' . $dirPath . " && chown www-data:www-data " . $dirPath,
    ];
} elseif (!$dirWritable || ($dirWritable && !$probeOk)) {
    $problems[] = [
        'title'  => 'Папка UPLOAD_DIR недоступна для записи',
        'detail' => 'Путь: ' . $dirPath . ($probeErr !== '' ? ' — ' . $probeErr : ''),
        'fix'    => 'chown -R www-data:www-data ' . $dirPath . "\n"
                  . 'Если включён SELinux: chcon -R -t httpd_sys_rw_content_t ' . $dirPath,
    ];
}
$allOk = empty($problems);

// ---------- Переменные шаблона ----------
$pageTitle      = 'Диагностика вложений — check_attachments';
$currentPage    = '';
$showUsersLink  = false;
$isAdmin        = false;
$sidebarContent = '';

$extraHead = <<<'HEAD'
<style>
.mono-pre {
    background: #f8f9fa;
    border: 1px solid #dee2e6;
    border-radius: 8px;
    padding: .75rem 1rem;
    font-size: .82rem;
    overflow-x: auto;
    margin: 0;
    white-space: pre;
}
.fix-pre {
    background: #fff8e1;
    border: 1px dashed #ffb300;
    border-radius: 8px;
    padding: .6rem .9rem;
    font-size: .85rem;
    margin: .4rem 0 0;
    white-space: pre;
    overflow-x: auto;
}
.verdict-ok  { border-left: 5px solid #198754; }
.verdict-bad { border-left: 5px solid #dc3545; }
.kv-table th { width: 260px; background: #f8f9fa; }
</style>
HEAD;

require 'templates/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <h3 class="fw-bold mb-0"><i class="bi bi-stethoscope text-danger"></i> Диагностика вложений</h3>
    <a href="index.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> Назад</a>
</div>

<?php if ($allOk): ?>
    <div class="alert alert-success verdict-ok mb-4">
        <strong><i class="bi bi-check-circle"></i> Вердикт: критичных проблем не найдено.</strong><br>
        Хранилище вложений полностью исправно: таблица есть, индексы не ограничивают количество файлов,
        папка UPLOAD_DIR доступна для записи. Если файлы всё равно не прикрепляются —
        откройте <a href="logs.php" class="alert-link">Журнал (logs.php)</a> и найдите события
        <code>attachment_error</code>: после step12c они содержат точную причину каждого сбоя.
    </div>
<?php else: ?>
    <div class="alert alert-danger verdict-bad mb-4">
        <strong><i class="bi bi-exclamation-triangle"></i> Вердикт: найдено проблем — <?= count($problems) ?></strong><br>
        Ниже каждая проблема и готовая команда исправления. Пункты исправляются один раз, по очереди.
    </div>
<?php endif; ?>

<div class="row g-4">

    <!-- ===== Вердикт: проблемы и исправления ===== -->
    <?php foreach ($problems as $p): ?>
        <div class="col-12">
            <div class="card shadow-sm border-0 verdict-bad">
                <div class="card-body">
                    <h5 class="fw-bold mb-2"><i class="bi bi-wrench text-danger"></i> <?= htmlspecialchars($p['title']) ?></h5>
                    <p class="mb-2"><?= htmlspecialchars($p['detail']) ?></p>
                    <div class="fw-semibold small text-muted mb-1">Исправление (выполнить на сервере / в MySQL):</div>
                    <pre class="fix-pre"><?= htmlspecialchars($p['fix']) ?></pre>
                </div>
            </div>
        </div>
    <?php endforeach; ?>

    <!-- ===== 1. PHP-настройки загрузки ===== -->
    <div class="col-lg-6">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-body">
                <h5 class="fw-bold mb-3"><i class="bi bi-gear"></i> 1. Настройки PHP (веб-сервер)</h5>
                <table class="table table-sm table-bordered kv-table mb-2">
                    <?php foreach ($phpUpload as $k => $v): ?>
                        <tr>
                            <th><?= htmlspecialchars($k) ?></th>
                            <td class="fw-semibold"><?= htmlspecialchars((string)$v) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <tr><th>SAPI</th><td class="fw-semibold"><?= htmlspecialchars($sapiPhp) ?></td></tr>
                    <tr><th>Загруженный php.ini</th><td class="fw-semibold text-break"><?= htmlspecialchars($loadedIni) ?></td></tr>
                </table>
                <small class="text-muted">
                    Это значения именно того PHP, который обрабатывает запросы браузера.
                    php.ini у CLI (<code>php -i</code>) — другой файл, его правки на сайт не влияют.
                    Если значения здесь не 100M — вы правили не тот ini: проверьте путь выше,
                    примените правки к нему и перезапустите PHP-FPM/Apache.
                    Ограничение одновременных файлов: <code>max_file_uploads</code> — при выборе
                    большего количества за один раз «лишние» файлы отбрасываются PHP (теперь это
                    показывает явную ошибку, а не молчаливую потерю).
                </small>
            </div>
        </div>
    </div>

    <!-- ===== 2. Папка хранения ===== -->
    <div class="col-lg-6">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-body">
                <h5 class="fw-bold mb-3"><i class="bi bi-folder"></i> 2. Папка хранения файлов</h5>
                <table class="table table-sm table-bordered kv-table mb-2">
                    <tr><th>UPLOAD_DIR</th><td class="text-break"><?= htmlspecialchars($dirPath) ?></td></tr>
                    <tr><th>Существует</th><td class="fw-semibold"><?= $dirExists ? '✅ да' : '❌ нет' ?></td></tr>
                    <tr><th>Доступна для записи</th><td class="fw-semibold"><?= $dirWritable ? '✅ да' : '❌ нет' ?></td></tr>
                    <tr><th>Пробная запись</th><td class="fw-semibold"><?= $dirWritable ? ($probeOk ? '✅ прошла' : '❌ ' . htmlspecialchars($probeErr)) : '—' ?></td></tr>
                    <tr>
                        <th>Свободно на диске</th>
                        <td class="fw-semibold"><?= $dirFree !== null ? formatBytes((int)$dirFree) : '—' ?></td>
                    </tr>
                </table>
                <small class="text-muted">
                    Папка вне webroot; файлы отдаются через download.php. Если пробная запись не прошла —
                    исправьте права (см. вердикт), иначе move_uploaded_file будет падать.
                </small>
            </div>
        </div>
    </div>

    <!-- ===== 3. Таблица claim_attachments ===== -->
    <div class="col-12">
        <div class="card shadow-sm border-0">
            <div class="card-body">
                <h5 class="fw-bold mb-3"><i class="bi bi-table"></i> 3. Таблица claim_attachments</h5>
                <?php if (!$tableExists): ?>
                    <div class="alert alert-danger mb-0">❌ Таблицы нет. Ошибка СУБД: <?= htmlspecialchars($tblErr) ?></div>
                <?php else: ?>
                    <div class="row g-3">
                        <div class="col-md-4">
                            <table class="table table-sm table-bordered kv-table mb-0">
                                <tr><th>Всего вложений</th><td class="fw-bold"><?= $totalRows ?></td></tr>
                                <?php foreach (['claim' => 'Файлы претензии', 'response' => 'Файлы ответа'] as $k => $lbl): ?>
                                    <tr><th><?= $lbl ?></th><td class="fw-bold"><?= $kindCounts[$k] ?? 0 ?></td></tr>
                                <?php endforeach; ?>
                            </table>
                            <small class="text-muted d-block mt-2">
                                Проверка мультизагрузки: в списке ниже должны быть претензии с 2+ файлами.
                                Если список пуст, а вы уже прикрепляли несколько — вложения не регистрировались.
                            </small>
                            <?php if (!empty($multiClaims)): ?>
                                <div class="mt-2">
                                    <?php foreach ($multiClaims as $mc): ?>
                                        <a class="d-block" href="check_attachments.php?claim_id=<?= (int)$mc['claim_id'] ?>">
                                            претензия №<?= (int)$mc['claim_id'] ?> — <?= (int)$mc['cnt'] ?> файл(ов)
                                        </a>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <div class="text-muted small mt-2">— пока нет ни одной претензии с несколькими файлами —</div>
                            <?php endif; ?>
                        </div>
                        <div class="col-md-8">
                            <div class="fw-semibold small text-muted mb-1">Индексы (SHOW INDEX):</div>
                            <?php if (!empty($idxRows)): ?>
                                <table class="table table-sm table-bordered mb-2">
                                    <thead><tr><th>Key_name</th><th>Non_unique</th><th>Column_name</th><th>Seq</th></tr></thead>
                                    <tbody>
                                        <?php foreach ($idxRows as $row): ?>
                                            <tr>
                                                <td><?= htmlspecialchars((string)$row['Key_name']) ?></td>
                                                <td><?= (int)$row['Non_unique'] === 0
                                                        ? '<span class="badge bg-danger">UNIQUE</span>'
                                                        : '<span class="badge bg-secondary">обычный</span>' ?></td>
                                                <td><?= htmlspecialchars((string)$row['Column_name']) ?></td>
                                                <td><?= (int)($row['Seq_in_index'] ?? 1) ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            <?php endif; ?>
                            <div class="fw-semibold small text-muted mb-1">Структура (SHOW CREATE TABLE):</div>
                            <pre class="mono-pre"><?= htmlspecialchars($createSql !== '' ? $createSql : '—') ?></pre>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- ===== 4. По конкретной претензии ===== -->
    <div class="col-12">
        <div class="card shadow-sm border-0">
            <div class="card-body">
                <h5 class="fw-bold mb-3"><i class="bi bi-search"></i> 4. Проверка конкретной претензии</h5>
                <form method="GET" class="row g-2 align-items-end mb-3">
                    <div class="col-auto">
                        <label class="form-label small mb-0">ID претензии</label>
                        <input type="number" name="claim_id" value="<?= $claimId > 0 ? $claimId : '' ?>"
                               class="form-control" style="width:140px;" placeholder="например 1">
                    </div>
                    <div class="col-auto">
                        <button type="submit" class="btn btn-primary"><i class="bi bi-arrow-right-circle"></i> Показать</button>
                    </div>
                </form>
                <?php if ($claimId > 0 && !$claimInfo): ?>
                    <div class="alert alert-warning mb-0">Претензия с ID <?= $claimId ?> не найдена.</div>
                <?php elseif ($claimInfo): ?>
                    <div class="table-responsive">
                        <table class="table table-sm table-bordered mb-2">
                            <thead><tr><th>#</th><th>Тип</th><th>Имя для показа</th><th>Имя на диске</th><th>Размер</th><th>Загружено</th></tr></thead>
                            <tbody>
                                <?php if (empty($claimRows)): ?>
                                    <tr><td colspan="6" class="text-center text-muted">в claim_attachments записей нет</td></tr>
                                <?php else: ?>
                                    <?php foreach ($claimRows as $i => $r): ?>
                                        <tr>
                                            <td><?= $i + 1 ?></td>
                                            <td><?= $r['file_kind'] === 'claim' ? 'претензия' : 'ответ' ?></td>
                                            <td><?= htmlspecialchars(attachmentDisplayName($r)) ?></td>
                                            <td><code><?= htmlspecialchars((string)$r['stored_name']) ?></code></td>
                                            <td><?= $r['file_size'] ? formatBytes((int)$r['file_size']) : '—' ?></td>
                                            <td><?= htmlspecialchars((string)$r['uploaded_at']) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    <small class="text-muted">
                        Legacy-поля записи: claim_file =
                        <code><?= $claimInfo['claim_file'] ? htmlspecialchars((string)$claimInfo['claim_file']) : 'NULL' ?></code>,
                        response_file =
                        <code><?= $claimInfo['response_file'] ? htmlspecialchars((string)$claimInfo['response_file']) : 'NULL' ?></code>.
                        Непустое legacy-поле при пустой таблице = файл лежит в старом одиночном поле (запись импорта
                        или миграция не применена).
                    </small>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php
require 'templates/footer.php';
