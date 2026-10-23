<?php
require 'config.php';
requireAuth();
requireRole('admin', 'manager');

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

                    <?php if ($delClaim['claim_file'] || $delClaim['response_file'] || !empty($additionalFiles)): ?>
                        <div class="alert alert-warning mx-auto" style="max-width: 500px;">
                            <i class="bi bi-paperclip"></i>
                            <strong>Вместе с претензией будут удалены все прикреплённые файлы!</strong>
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