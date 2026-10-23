<?php
require 'config.php';
requireAuth();
requireRole('admin', 'manager');

$error = '';
$message = '';
$claim = null;

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    die('❌ ID претензии не указан');
}

$stmt = $pdo->prepare("SELECT c.*, o.name as object_name
    FROM claims c
    LEFT JOIN objects o ON c.object_id = o.id
    WHERE c.id = ?");
$stmt->execute([$id]);
$claim = $stmt->fetch();

if (!$claim) {
    die('❌ Претензия с ID ' . $id . ' не найдена');
}

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

    // Валидация дат (сервер не доверяет браузеру)
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$received_date)) {
        $received_date = date('Y-m-d');
    }
    if ($response_date !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$response_date)) {
        $response_date = null;
    }

    if (empty($object_id)) $error = 'Выберите объект';
    elseif (empty($applicant)) $error = 'Укажите заявителя';
    elseif (empty($subject)) $error = 'Укажите предмет претензии';

    if (empty($error)) {
        $claim_file = $claim['claim_file'];
        $response_file = $claim['response_file'];
        // Запоминаем старые файлы, чтобы удалить их после замены (если они никому больше не нужны)
        $oldClaimFile = $claim['claim_file'];
        $oldResponseFile = $claim['response_file'];
        $additional_files = $claim['additional_files'] ? json_decode($claim['additional_files'], true) : [];
        $uploadDir = UPLOAD_DIR; // вне корня сайта, отдаётся через download.php
        if (!is_dir($uploadDir)) @mkdir($uploadDir, 0770, true);

        // ===== Файл претензии с MIME-проверкой =====
        if (!empty($_FILES['claim_file']['name'])) {
            if (!isAllowedFileType($_FILES['claim_file']['tmp_name'])) {
                $error = 'Недопустимый тип файла претензии';
            } else {
                $ext = uploadExtension($_FILES['claim_file']['name']);
                if ($ext === '') {
                    $error = 'Недопустимое расширение файла претензии (разрешены: pdf, jpg, jpeg, png, gif, doc, docx, xls, xlsx, ppt, pptx, txt)';
                } else {
                    $claim_file = 'claim_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                    if (!move_uploaded_file($_FILES['claim_file']['tmp_name'], $uploadDir . $claim_file)) {
                        $error = 'Не удалось сохранить файл претензии на сервер';
                    }
                }
            }
        }

        // ===== Файл ответа с MIME-проверкой =====
        if (empty($error) && !empty($_FILES['response_file']['name'])) {
            if (!isAllowedFileType($_FILES['response_file']['tmp_name'])) {
                $error = 'Недопустимый тип файла ответа';
            } else {
                $ext = uploadExtension($_FILES['response_file']['name']);
                if ($ext === '') {
                    $error = 'Недопустимое расширение файла ответа (разрешены: pdf, jpg, jpeg, png, gif, doc, docx, xls, xlsx, ppt, pptx, txt)';
                } else {
                    $response_file = 'resp_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                    if (!move_uploaded_file($_FILES['response_file']['tmp_name'], $uploadDir . $response_file)) {
                        $error = 'Не удалось сохранить файл ответа на сервер';
                    }
                }
            }
        }

        // ===== Дополнительные файлы с MIME-проверкой =====
        if (empty($error) && !empty($_FILES['additional_files']['name'][0])) {
            foreach ($_FILES['additional_files']['name'] as $key => $name) {
                if ($_FILES['additional_files']['error'][$key] === UPLOAD_ERR_OK) {
                    if (!isAllowedFileType($_FILES['additional_files']['tmp_name'][$key])) {
                        $error = 'Недопустимый тип доп. файла: ' . htmlspecialchars($name);
                        break;
                    }
                    $ext = uploadExtension($name);
                    if ($ext === '') {
                        $error = 'Недопустимое расширение доп. файла: ' . $name;
                        break;
                    }
                    $fname = 'add_' . time() . '_' . bin2hex(random_bytes(4)) . '_' . $key . '.' . $ext;
                    if (!move_uploaded_file($_FILES['additional_files']['tmp_name'][$key], $uploadDir . $fname)) {
                        $error = 'Не удалось сохранить доп. файл на сервер';
                        break;
                    }
                    $additional_files[] = $fname;
                }
            }
        }

        if (empty($error)) {
            $stmt = $pdo->prepare("UPDATE claims SET
                object_id = ?, applicant = ?, apartment_number = ?, direction = ?,
                received_from = ?, received_date = ?, subject = ?, current_status = ?,
                response_direction = ?, response_date = ?, recipient_id = ?,
                claim_file = ?, response_file = ?, additional_files = ?
                WHERE id = ?");

            $stmt->execute([
                $object_id,
                $applicant,
                $apartment_number ?: null,
                $direction,
                $received_from ?: null,
                $received_date,
                $subject,
                $current_status,
                $response_direction ?: null,
                $response_date,
                $recipient_id,
                $claim_file,
                $response_file,
                !empty($additional_files) ? json_encode($additional_files, JSON_UNESCAPED_UNICODE) : null,
                $id
            ]);

            // Удаляем заменённые старые файлы (если на них не ссылается другая претензия)
            if ($oldClaimFile && $oldClaimFile !== $claim_file) {
                deleteUploadIfUnused($pdo, $oldClaimFile, $id);
            }
            if ($oldResponseFile && $oldResponseFile !== $response_file) {
                deleteUploadIfUnused($pdo, $oldResponseFile, $id);
            }

            logAction('update', 'claim', $id, 'Отредактирована претензия: ' . mb_substr($subject, 0, 100));
            header('Location: index.php?updated=1');
            exit;
        }
    }
}

$csrfToken = getCsrfToken();
$user = currentUser();
$addFiles = $claim['additional_files'] ? json_decode($claim['additional_files'], true) : [];

// ===== Переменные для шаблона =====
$pageTitle = 'Редактировать претензию №' . getClaimNumber($pdo, $id);
$currentPage = 'edit';
$showUsersLink = false;
$isAdmin = false;

$extraHead = <<<'HEAD'
<style>
.dropzone {
    border: 2px dashed #ccc;
    border-radius: 10px;
    padding: 1rem;
    text-align: center;
    cursor: pointer;
    transition: all 0.2s ease;
    background: #f8f9fa;
    min-height: 70px;
    display: flex;
    align-items: center;
    justify-content: center;
}
.dropzone:hover { border-color: #0d6efd; background: #e9ecef; }
.dropzone.dragover { border-color: #0d6efd; background: #cfe2ff; border-style: solid; }
.dropzone.has-file { border-color: #198754; background: #d1e7dd; border-style: solid; }
.dropzone .dropzone-message { color: #6c757d; }
.dropzone .dropzone-preview { display: none; align-items: center; justify-content: center; gap: 8px; }
.dropzone.has-file .dropzone-message { display: none; }
.dropzone.has-file .dropzone-preview { display: flex; }
.dropzone-remove { line-height: 1; padding: 2px 8px; }
</style>
HEAD;

require 'templates/header.php';
?>
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h3 class="fw-bold mb-0"><i class="bi bi-pencil-square text-warning"></i> Редактировать претензию №<?= getClaimNumber($pdo, $id) ?></h3>
                <a href="index.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> Назад</a>
            </div>

            <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show">❌ <?= htmlspecialchars($error) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
            <?php endif; ?>

            <div class="card shadow-sm border-0">
                <div class="card-body p-4">
                    <form method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">

                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label fw-semibold">Объект <span class="text-danger">*</span></label>
                                <select name="object_id" class="form-select" id="editObjectSelect" required>
                                    <option value="">— Выберите объект —</option>
                                    <?php foreach ($objects as $obj): ?>
                                        <option value="<?= $obj['id'] ?>" <?= $obj['id'] == $claim['object_id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($obj['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-semibold">Заявитель <span class="text-danger">*</span></label>
                                <input type="text" name="applicant" class="form-control" value="<?= htmlspecialchars($claim['applicant']) ?>" required>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label fw-semibold">№ квартиры</label>
                                <input type="text" name="apartment_number" class="form-control" value="<?= htmlspecialchars($claim['apartment_number'] ?? '') ?>">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label fw-semibold">Направление</label>
                                <select name="direction" class="form-select">
                                    <option value="incoming" <?= $claim['direction'] === 'incoming' ? 'selected' : '' ?>>📥 Входящая</option>
                                    <option value="outgoing" <?= $claim['direction'] === 'outgoing' ? 'selected' : '' ?>>📤 Исходящая</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-semibold">От кого получено</label>
                                <input type="text" name="received_from" class="form-control" value="<?= htmlspecialchars($claim['received_from'] ?? '') ?>">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label fw-semibold">Дата получения</label>
                                <input type="date" name="received_date" class="form-control" value="<?= $claim['received_date'] ?? date('Y-m-d') ?>">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label fw-semibold">Текущий статус</label>
                                <input type="text" name="current_status" class="form-control" value="<?= htmlspecialchars($claim['current_status'] ?? '') ?>">
                            </div>
                            <div class="col-12">
                                <label class="form-label fw-semibold">Предмет претензии <span class="text-danger">*</span></label>
                                <textarea name="subject" class="form-control" rows="3" required><?= htmlspecialchars($claim['subject']) ?></textarea>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-semibold">Направление ответа</label>
                                <input type="text" name="response_direction" class="form-control" value="<?= htmlspecialchars($claim['response_direction'] ?? '') ?>">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label fw-semibold">Дата ответа</label>
                                <input type="date" name="response_date" class="form-control" value="<?= $claim['response_date'] ?? '' ?>">
                            </div>

                            <div class="col-md-5">
                                <label class="form-label fw-semibold">🏢 Получатель (компания)</label>
                                <select name="recipient_id" class="form-select" id="editRecipientSelect">
                                    <option value="">— Сначала выберите объект —</option>
                                </select>
                            </div>

                            <div class="col-md-5">
                                <label class="form-label fw-semibold">📄 Файл претензии</label>
                                <div class="dropzone" data-input="claim_file">
                                    <div class="dropzone-message">
                                        <i class="bi bi-cloud-upload fs-2 d-block mb-2"></i>
                                        <span>Перетащите файл или <strong>нажмите</strong></span>
                                    </div>
                                    <div class="dropzone-preview">
                                        <i class="bi bi-file-earmark-check text-success fs-4"></i>
                                        <span class="dropzone-filename fw-bold ms-2"></span>
                                        <button type="button" class="btn btn-sm btn-outline-danger ms-2 dropzone-remove">✕</button>
                                    </div>
                                </div>
                                <input type="file" name="claim_file" class="d-none" id="claim_file">
                                <?php if ($claim['claim_file']): ?>
                                    <small class="text-muted mt-1 d-block">Текущий: <a href="<?= uploadLink($claim['claim_file']) ?>" target="_blank">📎 <?= $claim['claim_file'] ?></a></small>
                                <?php endif; ?>
                            </div>

                            <div class="col-md-5">
                                <label class="form-label fw-semibold">📄 Файл ответа</label>
                                <div class="dropzone" data-input="response_file">
                                    <div class="dropzone-message">
                                        <i class="bi bi-cloud-upload fs-2 d-block mb-2"></i>
                                        <span>Перетащите файл или <strong>нажмите</strong></span>
                                    </div>
                                    <div class="dropzone-preview">
                                        <i class="bi bi-file-earmark-check text-success fs-4"></i>
                                        <span class="dropzone-filename fw-bold ms-2"></span>
                                        <button type="button" class="btn btn-sm btn-outline-danger ms-2 dropzone-remove">✕</button>
                                    </div>
                                </div>
                                <input type="file" name="response_file" class="d-none" id="response_file">
                                <?php if ($claim['response_file']): ?>
                                    <small class="text-muted mt-1 d-block">Текущий: <a href="<?= uploadLink($claim['response_file']) ?>" target="_blank">📎 <?= $claim['response_file'] ?></a></small>
                                <?php endif; ?>
                            </div>

                            <div class="col-md-5">
                                <label class="form-label fw-semibold">📎 Дополнительные файлы</label>
                                <div class="dropzone" data-input="additional_files">
                                    <div class="dropzone-message">
                                        <i class="bi bi-cloud-upload fs-2 d-block mb-2"></i>
                                        <span>Перетащите файлы или <strong>нажмите</strong></span>
                                    </div>
                                    <div class="dropzone-preview">
                                        <i class="bi bi-files text-success fs-4"></i>
                                        <span class="dropzone-filename fw-bold ms-2"></span>
                                        <button type="button" class="btn btn-sm btn-outline-danger ms-2 dropzone-remove">✕</button>
                                    </div>
                                </div>
                                <input type="file" name="additional_files[]" class="d-none" multiple id="additional_files">
                                <?php if (!empty($addFiles)): ?>
                                    <small class="text-muted mt-1 d-block">Текущие:
                                    <?php foreach ($addFiles as $af): ?>
                                        <a href="<?= uploadLink($af) ?>" target="_blank">📎 <?= $af ?></a>
                                    <?php endforeach; ?>
                                    </small>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="mt-4 d-flex gap-2">
                            <button type="submit" class="btn btn-warning btn-lg px-5">
                                <i class="bi bi-check-lg"></i> Сохранить изменения
                            </button>
                            <a href="index.php" class="btn btn-outline-secondary btn-lg">
                                <i class="bi bi-x-lg"></i> Отмена
                            </a>
                        </div>
                    </form>
                </div>
            </div>

<script>
document.querySelectorAll('.dropzone').forEach(zone => {
    const inputId = zone.dataset.input;
    const input = document.getElementById(inputId);
    zone.addEventListener('click', () => input.click());
    zone.addEventListener('dragover', e => { e.preventDefault(); zone.classList.add('dragover'); });
    zone.addEventListener('dragleave', e => { e.preventDefault(); zone.classList.remove('dragover'); });
    zone.addEventListener('drop', e => {
        e.preventDefault();
        zone.classList.remove('dragover');
        if (e.dataTransfer.files.length) {
            input.files = e.dataTransfer.files;
            updateDropzone(zone, input);
        }
    });
    input.addEventListener('change', () => updateDropzone(zone, input));
    zone.querySelector('.dropzone-remove')?.addEventListener('click', e => {
        e.stopPropagation();
        input.value = '';
        zone.classList.remove('has-file');
    });
});
function updateDropzone(zone, input) {
    const count = input.files.length;
    const el = zone.querySelector('.dropzone-filename');
    if (count > 0) {
        el.textContent = count === 1 ? input.files[0].name : count + ' файлов';
        zone.classList.add('has-file');
    } else {
        zone.classList.remove('has-file');
    }
}
</script>
<script>
document.addEventListener("DOMContentLoaded", function() {
    const objectSelect = document.getElementById("editObjectSelect");
    const recipientSelect = document.getElementById("editRecipientSelect");
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

    const currentRecipientId = <?= $claim['recipient_id'] ? (int)$claim['recipient_id'] : 'null' ?>;

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
            if (currentRecipientId && c.id === currentRecipientId) {
                opt.selected = true;
            }
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