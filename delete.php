<?php
require 'config.php';
requireAuth();
requireRole('admin', 'manager');

// step12b: защита от устаревшего config.php (OPcache / неполная замена файлов)
if (!function_exists('normalizeUploadSlots')) {
    http_response_code(500);
    die('❌ config.php не обновлён (нет функций step12). Замените config.php из пакета '
        . 'и сбросьте OPcache / перезапустите PHP-FPM, затем обновите страницу.');
}

$id = (int)($_GET['id'] ?? 0);

$stmt = $pdo->prepare("SELECT applicant, subject, claim_file, response_file, additional_files FROM claims WHERE id = ?");
$stmt->execute([$id]);
$delClaim = $stmt->fetch();

if (!$delClaim) {
    header('Location: index.php?error=not_found');
    exit;
}

$error = '';
$additionalFiles = $delClaim['additional_files'] ? json_decode($delClaim['additional_files'], true) : [];

// Вложения из таблицы claim_attachments (step12)
$delAttachments = getClaimAttachments($pdo, $id);

// ===== CSRF-проверка + удаление через POST =====
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfToken();

    // Удаляем файлы с диска ТОЛЬКО если на них не ссылается другая претензия
    // (один файл может быть прикреплён к нескольким записям после импорта)
    deleteUploadIfUnused($pdo, $delClaim['claim_file'], $id);
    deleteUploadIfUnused($pdo, $delClaim['response_file'], $id);

    if (is_array($additionalFiles)) {
        foreach ($additionalFiles as $f) {
            deleteUploadIfUnused($pdo, (string)$f, $id);
        }
    }

    // Вложения из таблицы (step12): сначала строки, потом файлы с диска
    foreach ($delAttachments as $att) {
        try {
            $pdo->prepare("DELETE FROM claim_attachments WHERE id = ?")->execute([$att['id']]);
        } catch (PDOException $e) { /* таблицы нет — нечего удалять */ }
        deleteUploadIfUnused($pdo, (string)$att['stored_name'], $id);
    }

    logAction('delete', 'claim', $id, 'Удалена претензия: ' . mb_substr($delClaim['subject'], 0, 100));

    $stmt = $pdo->prepare("DELETE FROM claims WHERE id = ?");
    $stmt->execute([$id]);

    header('Location: index.php?deleted=1');
    exit;
}

$csrfToken = getCsrfToken();
$user = currentUser();

// ===== Переменные для шаблона =====
$pageTitle = 'Удалить претензию №' . getClaimNumber($pdo, $id);
$currentPage = 'delete';
$showUsersLink = false;
$isAdmin = false;
$extraHead = '';

require 'templates/header.php';
?>
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h3 class="fw-bold mb-0"><i class="bi bi-trash text-danger"></i> Удаление претензии</h3>
                <a href="view.php?id=<?= $id ?>" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> Назад</a>
            </div>

            <div class="card shadow-sm border-0">
                <div class="card-body text-center p-5">
                    <i class="bi bi-exclamation-triangle text-danger" style="font-size: 4rem;"></i>
                    <h4 class="fw-bold mt-3 text-danger">Вы уверены, что хотите удалить претензию?</h4>
                    <p class="text-muted mb-1">
                        <strong>Заявитель:</strong> <?= htmlspecialchars($delClaim['applicant']) ?>
                    </p>
                    <p class="text-muted mb-4">
                        <strong>Предмет:</strong> <?= htmlspecialchars(mb_substr($delClaim['subject'], 0, 200)) ?>
                    </p>

                    <?php if ($delClaim['claim_file'] || $delClaim['response_file'] || !empty($additionalFiles) || count($delAttachments) > 0): ?>
                        <div class="alert alert-warning mx-auto" style="max-width: 500px;">
                            <i class="bi bi-paperclip"></i>
                            <strong>Вместе с претензией будут удалены все прикреплённые файлы!
                            <?php if (count($delAttachments) > 0): ?>
                                (вложений: <?= count($delAttachments) ?>)
                            <?php endif; ?></strong>
                        </div>
                    <?php endif; ?>

                    <form method="POST" class="d-inline-block">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">

                        <button type="submit" class="btn btn-danger btn-lg px-5 me-2"
                                onclick="return confirm('Финальное подтверждение: удалить претензию №<?= $id ?>? Это действие необратимо.')">
                            <i class="bi bi-trash"></i> Да, удалить
                        </button>
                        <a href="view.php?id=<?= $id ?>" class="btn btn-outline-secondary btn-lg">
                            <i class="bi bi-x-lg"></i> Отмена
                        </a>
                    </form>
                </div>
            </div>
<?php
require 'templates/footer.php';