<?php
require 'config.php';
requireAuth();
requireRole('admin', 'manager');

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
    $attachmentWarnings = [];

    if (empty($error)) {
        // ===== Мультизагрузка: файлы претензии и ответа (step12) =====
        $claimSlots = normalizeUploadSlots($_FILES['claim_files'] ?? null);
        $responseSlots = normalizeUploadSlots($_FILES['response_files'] ?? null);

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
        }
    }

    if (empty($error)) {
        $stmt = $pdo->prepare("INSERT INTO claims
            (object_id, applicant, apartment_number, direction, received_from, received_date, subject, current_status, additional_notes, response_direction, response_date, recipient_id, claim_file, response_file, additional_files, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, NULL, NULL, ?)");

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

                            <div class="col-md-5">
                                <div class="upload-block">
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

                            <div class="col-md-5">
                                <div class="upload-block">
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

                            <div class="col-md-5">
                                <div class="upload-block">
                                    <label class="form-label fw-semibold">📎 Дополнительные файлы <small class="text-muted">(можно несколько)</small></label>
                                    <div class="dropzone" data-input="additional_files">
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

document.querySelectorAll('.dropzone').forEach(zone => {
    const inputId = zone.dataset.input;
    const input = document.getElementById(inputId);
    const maxFiles = parseInt(zone.dataset.max || ATTACHMENTS_MAX_PER_KIND, 10);

    zone.addEventListener('click', () => input.click());

    zone.addEventListener('dragover', e => { e.preventDefault(); zone.classList.add('dragover'); });
    zone.addEventListener('dragleave', e => { e.preventDefault(); zone.classList.remove('dragover'); });
    zone.addEventListener('drop', e => {
        e.preventDefault();
        zone.classList.remove('dragover');
        if (e.dataTransfer.files.length) {
            setDropzoneFiles(input, e.dataTransfer.files, maxFiles);
            updateDropzone(zone, input);
        }
    });

    input.addEventListener('change', () => {
        if (input.files.length > maxFiles) {
            alert('Можно выбрать не более ' + maxFiles + ' файлов в этом блоке');
            input.value = '';
        }
        updateDropzone(zone, input);
    });
});

function setDropzoneFiles(input, fileList, maxFiles) {
    const dt = new DataTransfer();
    let skipped = 0;
    [...fileList].forEach(f => {
        if (dt.files.length < maxFiles) dt.items.add(f); else skipped++;
    });
    input.value = '';
    input.files = dt.files;
    if (skipped > 0) alert('Пропущено ' + skipped + ' файл(ов) — лимит ' + maxFiles);
}

function updateDropzone(zone, input) {
    const preview = zone.querySelector('.dropzone-preview');
    const count = input.files.length;
    preview.innerHTML = '';
    if (count > 0) {
        [...input.files].forEach(f => {
            const line = document.createElement('div');
            line.className = 'att-file-line';
            line.innerHTML = '<i class="bi bi-file-earmark text-secondary"></i>'
                + '<span class="att-fname"></span>'
                + '<small class="text-muted att-fsize"></small>'
                + '<button type="button" class="btn btn-sm btn-outline-danger att-file-remove" title="Убрать файл">✕</button>';
            line.querySelector('.att-fname').textContent = f.name;
            line.querySelector('.att-fsize').textContent = formatBytesJs(f.size);
            line.querySelector('.att-file-remove').addEventListener('click', e => {
                e.stopPropagation();
                const dt = new DataTransfer();
                [...input.files].forEach(x => { if (x !== f) dt.items.add(x); });
                input.value = '';
                input.files = dt.files;
                updateDropzone(zone, input);
            });
            preview.appendChild(line);
        });
        zone.classList.add('has-file');
    } else {
        zone.classList.remove('has-file');
    }
}
</script>
<script>
document.addEventListener("DOMContentLoaded", function() {
    const objectSelect = document.getElementById("addObjectSelect");
    const recipientSelect = document.getElementById("addRecipientSelect");

    if (!objectSelect || !recipientSelect) return;

    const companyMap = {};
    <?php
    $allObjects = $pdo->query("SELECT id FROM objects")->fetchAll();
    foreach ($allObjects as $obj):
        $comps = getCompaniesByObject($pdo, $obj['id']);
        if (count($comps)):
    ?>
        companyMap[<?= $obj["id"] ?>] = [
            <?php foreach ($comps as $comp): ?>
                {id: <?= $comp["id"] ?>, name: "<?= htmlspecialchars(addslashes($comp["name"])) ?>", role: "<?= $comp["role"] ?>"},
            <?php endforeach; ?>
        ];
    <?php endif; endforeach; ?>

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