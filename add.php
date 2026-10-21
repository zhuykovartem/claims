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
    $direction        = $_POST['direction'] ?? 'incoming';
    $received_from    = trim($_POST['received_from'] ?? '');
    $received_date    = $_POST['received_date'] ?? date('Y-m-d');
    $subject          = trim($_POST['subject'] ?? '');
    $current_status   = trim($_POST['current_status'] ?? 'Новая');
    $response_direction = trim($_POST['response_direction'] ?? '');
    $response_date    = $_POST['response_date'] ?? null;
    if (empty($response_date)) $response_date = null;
    $recipient_id     = !empty($_POST['recipient_id']) ? (int)$_POST['recipient_id'] : null;

    if (empty($object_id)) {
        $error = 'Выберите объект';
    } elseif (empty($applicant)) {
        $error = 'Укажите заявителя';
    } elseif (empty($subject)) {
        $error = 'Укажите предмет претензии';
    }

    $claim_file = null;
    $response_file = null;
    $additional_files = [];

    if (empty($error)) {
        $uploadDir = __DIR__ . '/uploads/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        // ===== Файл претензии с MIME-проверкой =====
        if (!empty($_FILES['claim_file']['name'])) {
            $tmp = $_FILES['claim_file']['tmp_name'];
            if (!isAllowedFileType($tmp)) {
                $error = 'Недопустимый тип файла претензии';
            } else {
                $ext = pathinfo($_FILES['claim_file']['name'], PATHINFO_EXTENSION);
                $claim_file = 'claim_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                move_uploaded_file($tmp, $uploadDir . $claim_file);
            }
        }

        // ===== Файл ответа с MIME-проверкой =====
        if (empty($error) && !empty($_FILES['response_file']['name'])) {
            $tmp = $_FILES['response_file']['tmp_name'];
            if (!isAllowedFileType($tmp)) {
                $error = 'Недопустимый тип файла ответа';
            } else {
                $ext = pathinfo($_FILES['response_file']['name'], PATHINFO_EXTENSION);
                $response_file = 'resp_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                move_uploaded_file($tmp, $uploadDir . $response_file);
            }
        }

        // ===== Дополнительные файлы с MIME-проверкой =====
        if (empty($error) && !empty($_FILES['additional_files']['name'][0])) {
            foreach ($_FILES['additional_files']['name'] as $key => $name) {
                if ($_FILES['additional_files']['error'][$key] === UPLOAD_ERR_OK) {
                    $tmp = $_FILES['additional_files']['tmp_name'][$key];
                    if (!isAllowedFileType($tmp)) {
                        $error = 'Недопустимый тип доп. файла: ' . htmlspecialchars($name);
                        break;
                    }
                    $ext = pathinfo($name, PATHINFO_EXTENSION);
                    $fname = 'add_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                    move_uploaded_file($tmp, $uploadDir . $fname);
                    $additional_files[] = $fname;
                }
            }
        }

        if (empty($error)) {
            $stmt = $pdo->prepare("INSERT INTO claims
                (object_id, applicant, apartment_number, direction, received_from, received_date, subject, current_status, response_direction, response_date, recipient_id, claim_file, response_file, additional_files, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

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
                $_SESSION['user_id']
            ]);

            $newId = $pdo->lastInsertId();
            logAction('create', 'claim', $newId, 'Добавлена претензия: ' . mb_substr($subject, 0, 100));
            header('Location: index.php?added=1');
            exit;
        }
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
.dropzone {
    border: 2px dashed #ccc;
    border-radius: 10px;
    padding: 1.5rem 1rem;
    text-align: center;
    cursor: pointer;
    transition: all 0.2s ease;
    background: #f8f9fa;
    min-height: 80px;
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

ob_start();
?>
            <div class="mt-auto">
                <hr class="sidebar-divider">
                <a href="logout.php" class="btn btn-outline-danger w-100 btn-sm"><i class="bi bi-box-arrow-right"></i> Выйти</a>
            </div>
<?php
$sidebarContent = ob_get_clean();

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
                                <input type="text" name="current_status" class="form-control" value="Новая" placeholder="Статус">
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

                            <div class="col-md-5">
                                <label class="form-label fw-semibold">📄 Файл претензии</label>
                                <div class="dropzone" data-input="claim_file">
                                    <div class="dropzone-message">
                                        <i class="bi bi-cloud-upload fs-2 d-block mb-2"></i>
                                        <span>Перетащите файл сюда или <strong>нажмите для выбора</strong></span>
                                    </div>
                                    <div class="dropzone-preview">
                                        <i class="bi bi-file-earmark-check text-success fs-4"></i>
                                        <span class="dropzone-filename fw-bold ms-2"></span>
                                        <button type="button" class="btn btn-sm btn-outline-danger ms-2 dropzone-remove" title="Удалить файл">✕</button>
                                    </div>
                                </div>
                                <input type="file" name="claim_file" class="d-none" id="claim_file">
                            </div>

                            <div class="col-md-5">
                                <label class="form-label fw-semibold">📄 Файл ответа</label>
                                <div class="dropzone" data-input="response_file">
                                    <div class="dropzone-message">
                                        <i class="bi bi-cloud-upload fs-2 d-block mb-2"></i>
                                        <span>Перетащите файл сюда или <strong>нажмите для выбора</strong></span>
                                    </div>
                                    <div class="dropzone-preview">
                                        <i class="bi bi-file-earmark-check text-success fs-4"></i>
                                        <span class="dropzone-filename fw-bold ms-2"></span>
                                        <button type="button" class="btn btn-sm btn-outline-danger ms-2 dropzone-remove" title="Удалить файл">✕</button>
                                    </div>
                                </div>
                                <input type="file" name="response_file" class="d-none" id="response_file">
                            </div>

                            <div class="col-md-5">
                                <label class="form-label fw-semibold">📎 Дополнительные файлы <small class="text-muted">(можно несколько)</small></label>
                                <div class="dropzone" data-input="additional_files">
                                    <div class="dropzone-message">
                                        <i class="bi bi-cloud-upload fs-2 d-block mb-2"></i>
                                        <span>Перетащите файлы сюда или <strong>нажмите для выбора</strong></span>
                                    </div>
                                    <div class="dropzone-preview">
                                        <i class="bi bi-files text-success fs-4"></i>
                                        <span class="dropzone-filename fw-bold ms-2"></span>
                                        <button type="button" class="btn btn-sm btn-outline-danger ms-2 dropzone-remove" title="Удалить файлы">✕</button>
                                    </div>
                                </div>
                                <input type="file" name="additional_files[]" class="d-none" multiple id="additional_files">
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
    const fileCount = input.files.length;
    const filenameEl = zone.querySelector('.dropzone-filename');
    if (fileCount > 0) {
        filenameEl.textContent = fileCount === 1 ? input.files[0].name : fileCount + ' файлов выбрано';
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