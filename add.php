<?php
require 'config.php';
requireAuth();
requireRole('admin', 'manager');

// step12b: защита от устаревшего config.php (OPcache / неполная замена файлов).
// Без неё страница молча обрезается посреди формы на первом же выводе
// ATTACHMENTS_MAX_PER_KIND — пользователь видит форму без блоков файлов и кнопки.
if (!function_exists('normalizeUploadSlots')) {
    http_response_code(500);
    die('❌ config.php не обновлён (нет функций step12). Замените config.php из пакета '
        . 'и сбросьте OPcache / перезапустите PHP-FPM, затем обновите страницу.');
}

$error = '';
$message = '';

$objects = $pdo->query("SELECT * FROM objects ORDER BY name")->fetchAll();

// ===== CSRF-проверка при POST =====
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfToken();

    $object_id        = (int)($_POST['object_id'] ?? 0);
    $applicant        = trim($_POST['applicant'] ?? '');
    $apartment_number = trim($_POST['apartment_number'] ?? '');
    $direction        = in_array($_POST['direction'] ?? '', ['incoming', 'outgoing'], true) ? $_POST['direction'] : 'incoming';
    $received_from    = trim($_POST['received_from'] ?? '');
    $received_date    = $_POST['received_date'] ?? date('Y-m-d');
    $subject          = trim($_POST['subject'] ?? '');
    $current_status   = trim($_POST['current_status'] ?? 'Новая');
    $response_direction = trim($_POST['response_direction'] ?? '');
    $response_date    = $_POST['response_date'] ?? null;
    if (empty($response_date)) $response_date = null;
    $recipient_id     = !empty($_POST['recipient_id']) ? (int)$_POST['recipient_id'] : null;
    $additional_notes = trim($_POST['additional_notes'] ?? '');

    // Валидация дат (сервер не доверяет браузеру)
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$received_date)) {
        $received_date = date('Y-m-d');
    }
    if ($response_date !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$response_date)) {
        $response_date = null;
    }

    if (empty($object_id)) {
        $error = 'Выберите объект';
    } elseif (empty($applicant)) {
        $error = 'Укажите заявителя';
    } elseif (empty($subject)) {
        $error = 'Укажите предмет претензии';
    }

    $claimSlots = [];
    $responseSlots = [];
    $additionalSlots = [];
    $additionalFileNames = [];
    $attachmentWarnings = [];

    if (empty($error)) {
        // ===== Мультизагрузка: файлы претензии и ответа (step12) =====
        $claimSlots = normalizeUploadSlots($_FILES['claim_files'] ?? null);
        $responseSlots = normalizeUploadSlots($_FILES['response_files'] ?? null);
        // step12b: доп. файлы снова обрабатываются при создании
        // (обработка была потеряна при переписывании формы — блок молча игнорировался)
        $additionalSlots = normalizeUploadSlots($_FILES['additional_files'] ?? null);

        if (count($claimSlots) > ATTACHMENTS_MAX_PER_KIND) {
            $error = 'Файлов претензии выбрано ' . count($claimSlots) . ' — не более ' . ATTACHMENTS_MAX_PER_KIND;
        } elseif (count($responseSlots) > ATTACHMENTS_MAX_PER_KIND) {
            $error = 'Файлов ответа выбрано ' . count($responseSlots) . ' — не более ' . ATTACHMENTS_MAX_PER_KIND;
        } else {
            // Валидация всех файлов ДО записи на диск и создания записи
            foreach ($claimSlots as $slot) {
                $msg = validateUploadSlot($slot, 'претензии');
                if ($msg !== '') { $error = $msg; break; }
            }
            if (empty($error)) {
                foreach ($responseSlots as $slot) {
                    $msg = validateUploadSlot($slot, 'ответа');
                    if ($msg !== '') { $error = $msg; break; }
                }
            }
            if (empty($error)) {
                foreach ($additionalSlots as $slot) {
                    $msg = validateUploadSlot($slot, 'доп. файла');
                    if ($msg !== '') { $error = $msg; break; }
                }
            }
        }
    }

    // step12b: сохранение доп. файлов на диск (до создания записи;
    // при сбое запись не создаётся, полусохранённые файлы удаляются)
    if (empty($error)) {
        foreach ($additionalSlots as $slot) {
            $ext = uploadExtension((string)$slot['name']);
            $fname = 'add_' . time() . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
            if (!@move_uploaded_file((string)$slot['tmp_name'], UPLOAD_DIR . $fname)) {
                $error = 'Не удалось сохранить доп. файл «' . $slot['name'] . '» на сервер';
                break;
            }
            $additionalFileNames[] = $fname;
        }
        if (!empty($error)) {
            foreach ($additionalFileNames as $f) { @unlink(UPLOAD_DIR . $f); }
            $additionalFileNames = [];
        }
    }

    if (empty($error)) {
        $stmt = $pdo->prepare("INSERT INTO claims
            (object_id, applicant, apartment_number, direction, received_from, received_date, subject, current_status, additional_notes, response_direction, response_date, recipient_id, claim_file, response_file, additional_files, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, NULL, ?, ?)");

        $stmt->execute([
            $object_id,
            $applicant,
            $apartment_number ?: null,
            $direction,
            $received_from ?: null,
            $received_date,
            $subject,
            $current_status,
            $additional_notes ?: null,
            $response_direction ?: null,
            $response_date,
            $recipient_id,
            !empty($additionalFileNames) ? json_encode(array_values($additionalFileNames), JSON_UNESCAPED_UNICODE) : null,
            $_SESSION['user_id']
        ]);

        $newId = (int)$pdo->lastInsertId();

        // Сохранение файлов и регистрация в claim_attachments
        // (валидация пройдена выше — здесь возможны только сбои диска/БД,
        // они не должны мешать созданной претензии: пишем в журнал)
        foreach ($claimSlots as $slot) {
            [$ok, $msg] = saveClaimAttachment($pdo, $newId, 'claim', $slot, (int)$_SESSION['user_id']);
            if (!$ok) { $attachmentWarnings[] = $msg; }
        }
        foreach ($responseSlots as $slot) {
            [$ok, $msg] = saveClaimAttachment($pdo, $newId, 'response', $slot, (int)$_SESSION['user_id']);
            if (!$ok) { $attachmentWarnings[] = $msg; }
        }
        if (!empty($attachmentWarnings)) {
            logAction('attachment_error', 'claim', $newId, 'Часть вложений не сохранена: ' . implode('; ', $attachmentWarnings));
            foreach ($attachmentWarnings as $aw) { flashWarning($aw); }
        }

        logAction('create', 'claim', $newId, 'Добавлена претензия: ' . mb_substr($subject, 0, 100)
            . (count($claimSlots) + count($responseSlots) > 0
                ? ' (вложений: ' . (count($claimSlots) + count($responseSlots)) . ')' : ''));
        header('Location: index.php?added=1');
        exit;
    }
}

$csrfToken = getCsrfToken();
$user = currentUser();

// ===== Переменные для шаблона =====
$pageTitle = 'Добавить претензию';
$currentPage = 'add';
$showUsersLink = false;
$isAdmin = false;

$extraHead = <<<'HEAD'
<style>
/* Блок загрузки: рамка + подложка, зоны хорошо отделены друг от друга */
.upload-block {
    border: 1px solid #d9e2ec;
    border-radius: 12px;
    background: #ffffff;
    padding: 0.9rem 1rem 1rem;
    height: 100%;
    box-shadow: 0 1px 3px rgba(16, 24, 40, 0.07);
}
.upload-block .form-label {
    width: 100%;
    margin-bottom: 0.75rem;
    padding-bottom: 0.5rem;
    border-bottom: 1px dashed #d9e2ec;   /* пунктирный разделитель заголовка */
}
.dropzone {
    border: 2px dashed #9fb0c0;
    border-radius: 10px;
    padding: 1.25rem 1rem;
    text-align: center;
    cursor: pointer;
    transition: all 0.2s ease;
    background: #f6f9fc;
    min-height: 96px;
    display: flex;
    align-items: center;
    justify-content: center;
}
.dropzone .bi-cloud-upload { color: #5b6b7c; }
.dropzone:hover { border-color: #0d6efd; background: #eef5ff; }
.dropzone.dragover { border-color: #0d6efd; background: #cfe2ff; border-style: solid; }
.dropzone.has-file { border-color: #198754; background: #d1e7dd; border-style: solid; }
.dropzone .dropzone-message { color: #6c757d; pointer-events: none; }
.dropzone .dropzone-preview {
    display: none;
    flex-direction: column;
    align-items: stretch;
    justify-content: center;
    gap: 6px;
    width: 100%;
}
.dropzone.has-file { background: #f0fdf4; }
.dropzone.has-file .dropzone-message { display: none; }
.dropzone.has-file .dropzone-preview { display: flex; }
.att-file-line {
    display: flex;
    align-items: center;
    gap: 6px;
    background: #ffffff;
    border: 1px solid #d9e2ec;
    border-radius: 8px;
    padding: 4px 8px;
    text-align: left;
}
.att-file-line .att-fname {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    font-weight: 600;
    max-width: 260px;
}
.att-file-line .att-file-remove { line-height: 1; padding: 1px 7px; margin-left: auto; }
.dropzone-remove { line-height: 1; padding: 2px 8px; }
</style>
HEAD;

require 'templates/header.php';
?>
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h3 class="fw-bold mb-0"><i class="bi bi-plus-circle text-success"></i> Добавить претензию</h3>
                <a href="index.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> Назад</a>
            </div>

            <?php $attSchemaProblem = attachmentsSchemaProblem($pdo); ?>
            <?php if ($attSchemaProblem): ?>
                <div class="alert alert-danger">
                    <strong><i class="bi bi-exclamation-triangle"></i> Проблема с хранилищем вложений.</strong><br>
                    <?= htmlspecialchars($attSchemaProblem) ?><br>
                    <small>Файлы при создании претензии не сохранятся, пока проблема не устранена.
                    Диагностика: <a href="check_attachments.php" class="alert-link">check_attachments.php</a></small>
                </div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show">❌ <?= htmlspecialchars($error) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
            <?php endif; ?>
            <?php if ($message): ?>
                <div class="alert alert-success alert-dismissible fade show">✅ <?= $message ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
            <?php endif; ?>

            <div class="card shadow-sm border-0">
                <div class="card-body p-4">
                    <form method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">

                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label fw-semibold">Объект <span class="text-danger">*</span></label>
                                <select name="object_id" class="form-select" id="addObjectSelect" required>
                                    <option value="">— Выберите объект —</option>
                                    <?php foreach ($objects as $obj): ?>
                                        <option value="<?= $obj['id'] ?>"><?= htmlspecialchars($obj['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-md-4">
                                <label class="form-label fw-semibold">Заявитель <span class="text-danger">*</span></label>
                                <input type="text" name="applicant" class="form-control" placeholder="ФИО или организация" required>
                            </div>

                            <div class="col-md-2">
                                <label class="form-label fw-semibold">№ квартиры</label>
                                <input type="text" name="apartment_number" class="form-control" placeholder="Напр. 42">
                            </div>

                            <div class="col-md-2">
                                <label class="form-label fw-semibold">Направление</label>
                                <select name="direction" class="form-select">
                                    <option value="incoming">📥 Входящая</option>
                                    <option value="outgoing">📤 Исходящая</option>
                                </select>
                            </div>

                            <div class="col-md-4">
                                <label class="form-label fw-semibold">От кого получено / Кому направлено</label>
                                <input type="text" name="received_from" class="form-control" placeholder="Кто передал претензию">
                            </div>

                            <div class="col-md-3">
                                <label class="form-label fw-semibold">Дата получения</label>
                                <input type="date" name="received_date" class="form-control" value="<?= date('Y-m-d') ?>">
                            </div>

                            <div class="col-md-3">
                                <label class="form-label fw-semibold">Текущий статус</label>
                                <input type="text" name="current_status" class="form-control" value="Новая" placeholder="Статус" list="statusOptions">
                                <datalist id="statusOptions">
                                    <?php foreach (getStatusOptions($pdo) as $statusOpt): ?>
                                        <option value="<?= htmlspecialchars($statusOpt) ?>"></option>
                                    <?php endforeach; ?>
                                </datalist>
                                <small class="text-muted">Выберите из списка или введите свой</small>
                            </div>

                            <div class="col-12">
                                <label class="form-label fw-semibold">Предмет претензии <span class="text-danger">*</span></label>
                                <textarea name="subject" class="form-control" rows="3" placeholder="Опишите суть претензии" required></textarea>
                            </div>

                            <div class="col-md-4">
                                <label class="form-label fw-semibold">Куда направлен ответ</label>
                                <input type="text" name="response_direction" class="form-control" placeholder="Куда направлен ответ">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label fw-semibold">Дата ответа</label>
                                <input type="date" name="response_date" class="form-control">
                            </div>

                            <div class="col-md-5">
                                <label class="form-label fw-semibold">🏢 Получатель (компания)</label>
                                <select name="recipient_id" class="form-select" id="addRecipientSelect">
                                    <option value="">— Сначала выберите объект —</option>
                                </select>
                            </div>

                            <div class="col-12">
                                <label class="form-label fw-semibold">📝 Дополнительно <small class="text-muted fw-normal">(заметки, нестандартные ситуации)</small></label>
                                <textarea name="additional_notes" class="form-control" rows="2" placeholder="Свободные заметки по претензии: договорённости, особые условия, ссылки на связанные документы и т.п."><?= htmlspecialchars($_POST['additional_notes'] ?? '') ?></textarea>
                            </div>

                            <div class="col-12 col-lg-4 d-flex">
                                <div class="upload-block w-100">
                                    <label class="form-label fw-semibold">📄 Файл претензии <small class="text-muted fw-normal">(можно несколько, до <?= ATTACHMENTS_MAX_PER_KIND ?>)</small></label>
                                    <div class="dropzone" data-input="claim_files">
                                        <div class="dropzone-message">
                                            <i class="bi bi-cloud-upload fs-2 d-block mb-2"></i>
                                            <span>Перетащите файлы сюда или <strong>нажмите для выбора</strong></span>
                                        </div>
                                        <div class="dropzone-preview"></div>
                                    </div>
                                    <input type="file" name="claim_files[]" class="d-none" multiple id="claim_files">
                                </div>
                            </div>

                            <div class="col-12 col-lg-4 d-flex">
                                <div class="upload-block w-100">
                                    <label class="form-label fw-semibold">📄 Файл ответа <small class="text-muted fw-normal">(можно несколько, до <?= ATTACHMENTS_MAX_PER_KIND ?>)</small></label>
                                    <div class="dropzone" data-input="response_files">
                                        <div class="dropzone-message">
                                            <i class="bi bi-cloud-upload fs-2 d-block mb-2"></i>
                                            <span>Перетащите файлы сюда или <strong>нажмите для выбора</strong></span>
                                        </div>
                                        <div class="dropzone-preview"></div>
                                    </div>
                                    <input type="file" name="response_files[]" class="d-none" multiple id="response_files">
                                </div>
                            </div>

                            <div class="col-12 col-lg-4 d-flex">
                                <div class="upload-block w-100">
                                    <label class="form-label fw-semibold">📎 Дополнительные файлы <small class="text-muted">(можно несколько)</small></label>
                                    <div class="dropzone" data-input="additional_files" data-max="0">
                                        <div class="dropzone-message">
                                            <i class="bi bi-cloud-upload fs-2 d-block mb-2"></i>
                                            <span>Перетащите файлы сюда или <strong>нажмите для выбора</strong></span>
                                        </div>
                                        <div class="dropzone-preview"></div>
                                    </div>
                                    <input type="file" name="additional_files[]" class="d-none" multiple id="additional_files">
                                </div>
                            </div>
                        </div>

                        <div class="mt-4 d-flex gap-2">
                            <button type="submit" class="btn btn-success btn-lg px-5">
                                <i class="bi bi-check-lg"></i> Сохранить претензию
                            </button>
                            <a href="index.php" class="btn btn-outline-secondary btn-lg">
                                <i class="bi bi-x-lg"></i> Отмена
                            </a>
                        </div>
                    </form>
                </div>
            </div>

<script>
const ATTACHMENTS_MAX_PER_KIND = <?= ATTACHMENTS_MAX_PER_KIND ?>;

function formatBytesJs(b) {
    if (!b || b <= 0) return '';
    const units = ['Б', 'КБ', 'МБ', 'ГБ'];
    let i = 0;
    while (b >= 1024 && i < units.length - 1) { b /= 1024; i++; }
    return Math.round(b * 10) / 10 + ' ' + units[i];
}

// step12d: НАКОПЛЕНИЕ файлов в dropzone вместо замены.
// Стандартное поведение <input type="file"> при каждом новом выборе в окне
// браузера ЗАМЕНЯЕТ предыдущий список. Из-за этого при прикреплении файлов
// по одному (без промежуточного сохранения) на сервер уходил только
// последний выбранный файл — в claim_attachments появлялась одна запись.
// Теперь выбор НАКАПЛИВАЕТСЯ: новый файл добавляется к уже выбранным,
// дубликаты пропускаются, лимит блока соблюдается, ведётся счётчик.
document.querySelectorAll('.dropzone').forEach(zone => {
    const inputId = zone.dataset.input;
    const input = document.getElementById(inputId);
    // step12f: data-max="0" — блок БЕЗ лимита (дополнительные файлы);
    // иначе лимит берётся из data-max, а по умолчанию — ATTACHMENTS_MAX_PER_KIND
    const maxFiles = zone.dataset.max === '0'
        ? Infinity
        : parseInt(zone.dataset.max || ATTACHMENTS_MAX_PER_KIND, 10);
    const canDt = (typeof DataTransfer !== 'undefined');
    let picked = []; // ещё не отправленные файлы этого блока

    const fileKey = f => f.name + '|' + f.size + '|' + (f.lastModified || 0);

    // Синхронизация input.files с накопителем: форма отправит именно его
    function writeBack() {
        if (!canDt) return;
        const dt = new DataTransfer();
        picked.forEach(f => dt.items.add(f));
        input.value = '';
        input.files = dt.files;
    }

    // Перерисовка списка выбранных файлов и счётчика
    function render() {
        const preview = zone.querySelector('.dropzone-preview');
        const source = canDt ? picked : Array.from(input.files);
        preview.innerHTML = '';
        source.forEach((f, idx) => {
            const line = document.createElement('div');
            line.className = 'att-file-line';
            line.innerHTML = '<i class="bi bi-file-earmark text-secondary"></i>'
                + '<span class="att-fname"></span>'
                + '<small class="text-muted att-fsize"></small>'
                + '<button type="button" class="btn btn-sm btn-outline-danger att-file-remove" title="Убрать файл">✕</button>';
            line.querySelector('.att-fname').textContent = f.name;
            line.querySelector('.att-fsize').textContent = formatBytesJs(f.size);
            const removeBtn = line.querySelector('.att-file-remove');
            if (canDt) {
                removeBtn.addEventListener('click', e => {
                    e.stopPropagation();
                    picked.splice(idx, 1);
                    writeBack();
                    render();
                });
            } else {
                removeBtn.style.display = 'none'; // без DataTransfer пересобрать FileList нельзя
            }
            preview.appendChild(line);
        });
        let counter = zone.querySelector('.dropzone-count');
        if (!counter) {
            counter = document.createElement('small');
            counter.className = 'dropzone-count text-muted d-block mt-1';
            zone.appendChild(counter);
        }
        const limitText = maxFiles === Infinity ? '' : ' из ' + maxFiles;
        counter.textContent = source.length > 0
            ? 'Выбрано файлов: ' + source.length + limitText
            : '';
        zone.classList.toggle('has-file', source.length > 0);
    }

    // ДОБАВИТЬ файлы к накопленным (именно добавить, не заменить!)
    function addFiles(fileList) {
        let dup = 0, over = 0;
        Array.from(fileList).forEach(f => {
            if (picked.some(x => fileKey(x) === fileKey(f))) { dup++; return; }
            if (picked.length >= maxFiles) { over++; return; }
            picked.push(f);
        });
        writeBack();
        if (over > 0) alert('Лимит блока — ' + maxFiles + ' файл(ов). Не добавлено: ' + over);
        if (dup > 0) alert('Такой файл уже выбран — пропущено: ' + dup);
        render();
    }

    zone.addEventListener('click', () => input.click());

    zone.addEventListener('dragover', e => { e.preventDefault(); zone.classList.add('dragover'); });
    zone.addEventListener('dragleave', e => { e.preventDefault(); zone.classList.remove('dragover'); });
    zone.addEventListener('drop', e => {
        e.preventDefault();
        zone.classList.remove('dragover');
        if (e.dataTransfer.files.length) {
            if (canDt) {
                addFiles(e.dataTransfer.files);
            } else {
                setDropzoneFiles(input, e.dataTransfer.files, maxFiles);
                render();
            }
        }
    });

    input.addEventListener('change', () => {
        if (canDt) {
            if (input.files.length) addFiles(input.files);
            // Сброс значения, чтобы повторный выбор ТОГО ЖЕ файла снова
            // вызвал change; накопленный список возвращаем в input сразу,
            // чтобы форма при сохранении отправила все выбранные файлы.
            input.value = '';
            writeBack();
        } else {
            if (input.files.length > maxFiles) {
                alert('Можно выбрать не более ' + maxFiles + ' файлов в этом блоке');
                input.value = '';
            }
            render();
        }
    });
});

// Резерв для очень старых браузеров без конструктора DataTransfer:
// прежнее поведение — замена набора файлов одним перетаскиванием.
function setDropzoneFiles(input, fileList, maxFiles) {
    const dt = new DataTransfer();
    let skipped = 0;
    Array.from(fileList).forEach(f => {
        if (dt.files.length < maxFiles) dt.items.add(f); else skipped++;
    });
    input.value = '';
    input.files = dt.files;
    if (skipped > 0) alert('Пропущено ' + skipped + ' файл(ов) — лимит ' + maxFiles);
}
</script>
<script>
document.addEventListener("DOMContentLoaded", function() {
    const objectSelect = document.getElementById("addObjectSelect");
    const recipientSelect = document.getElementById("addRecipientSelect");

    if (!objectSelect || !recipientSelect) return;

    // step12f: карта компаний собирается в PHP и выводится ОДНИМ json_encode.
    // Прежняя связка htmlspecialchars(addslashes()) не предназначена для JS-строк:
    // кавычки и слэши в названии компании ломали JS-объект и выпадающие списки.
    const companyMap = <?php
    $companyMapJs = [];
    foreach ($pdo->query("SELECT id FROM objects")->fetchAll() as $obj) {
        $comps = getCompaniesByObject($pdo, $obj['id']);
        if (count($comps)) {
            $companyMapJs[(int)$obj['id']] = array_map(
                static fn($c) => ['id' => (int)$c['id'], 'name' => (string)$c['name'], 'role' => (string)$c['role']],
                array_values($comps)
            );
        }
    }
    echo json_encode($companyMapJs, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    ?>;

    function updateRecipients() {
        const objId = objectSelect.value;
        recipientSelect.innerHTML = '<option value="">— Выберите получателя —</option>';
        const companies = companyMap[objId] || [];
        const roleLabels = {
            developer: "🏗️ Застройщик",
            technical_customer: "🔧 Тех. заказчик",
            general_contractor: "👷 Ген. подрядчик",
            other: "📋 Другое",
        };
        companies.forEach(function(c) {
            const opt = document.createElement("option");
            opt.value = c.id;
            opt.textContent = c.name + " (" + (roleLabels[c.role] || c.role) + ")";
            recipientSelect.appendChild(opt);
        });
        if (companies.length === 0) {
            recipientSelect.innerHTML = '<option value="">— Нет привязанных компаний —</option>';
        }
    }

    objectSelect.addEventListener("change", updateRecipients);
    if (objectSelect.value) updateRecipients();
});
</script>
<?php
require 'templates/footer.php';