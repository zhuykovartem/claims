<?php
require 'config.php';
requireAuth();

$id = (int)($_GET['id'] ?? 0);

$stmt = $pdo->prepare("SELECT c.*, o.name as object_name, u.full_name as created_by_name, co.name as recipient_name
    FROM claims c
    LEFT JOIN objects o ON c.object_id = o.id
    LEFT JOIN users u ON c.created_by = u.id
    LEFT JOIN companies co ON c.recipient_id = co.id
    WHERE c.id=?");
$stmt->execute([$id]);
$c = $stmt->fetch();

if (!$c) {
    header('Location: index.php');
    exit;
}

$color = getClaimStatusColor($c);
$days = getDaysPassed($c);
$deadline = getResponseDeadline($c['received_date']);   // дата «Срок ответа до»
$answered = !empty($c['response_date']);                // ответ дан — счётчик остановлен
$additional_files = $c['additional_files'] ? json_decode($c['additional_files'], true) : [];

// ===== Переменные для шаблона =====
$pageTitle = 'Претензия №' . getClaimNumber($pdo, $c['id']) . ' — Просмотр';
$currentPage = 'view';
$showUsersLink = false;
$isAdmin = false;

$extraHead = <<<'HEAD'
<style>
.detail-header {
    border-left: 5px solid;
    padding: 1rem 1.5rem;
    border-radius: 12px;
}
.detail-header.green { border-color: #4caf50; background: #e8f5e9; }
.detail-header.yellow { border-color: #ff9800; background: #fff8e1; }
.detail-header.red { border-color: #f44336; background: #ffebee; }
.detail-header.purple { border-color: #9c27b0; background: #f3e5f5; }
.file-card {
    transition: transform 0.15s, box-shadow 0.15s;
    border-radius: 10px;
}
.file-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 5px 15px rgba(0,0,0,0.1);
}
.info-table th {
    width: 200px;
    background: #f8f9fa;
}
</style>
HEAD;

$sidebarContent = '';

require 'templates/header.php';
?>
<div class="detail-header <?= $color ?> d-flex justify-content-between align-items-center mb-4">
    <div>
        <h3 class="fw-bold mb-0">
            <i class="bi bi-file-earmark-text"></i> Претензия №<?= getClaimNumber($pdo, $c['id']) ?>
        </h3>
        <small class="text-muted">
            Создана: <?= date('d.m.Y H:i', strtotime($c['created_at'])) ?>
            | Автор: <?= htmlspecialchars($c['created_by_name'] ?? 'Система') ?>
        </small>
    </div>
    <div>
        <?php if (hasRole('admin', 'manager')): ?>
        <a href="edit.php?id=<?= $c['id'] ?>" class="btn btn-warning">
            <i class="bi bi-pencil"></i> Редактировать
        </a>
        <a href="delete.php?id=<?= $c['id'] ?>" class="btn btn-danger"
           onclick="return confirm('Вы уверены, что хотите удалить претензию №<?= $c['id'] ?>?')">
            <i class="bi bi-trash"></i> Удалить
        </a>
        <?php endif; ?>
        <a href="index.php" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left"></i> Реестр
        </a>
    </div>
</div>

<?php if (isset($_GET['updated'])): ?>
    <div class="alert alert-info alert-dismissible fade show">
        📝 Изменения сохранены.
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<div class="row g-4">
    <!-- Левая колонка: основная информация -->
    <div class="col-md-7">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-body">
                <h5 class="fw-bold mb-3">
                    <i class="bi bi-info-circle"></i> Основная информация
                </h5>
                <table class="table info-table table-bordered">
                    <tr>
                        <th>Объект строительства</th>
                        <td>
                            <span class="badge bg-info fs-6">
                                <?= htmlspecialchars($c['object_name']) ?>
                            </span>
                        </td>
                    </tr>
                    <tr>
                        <th>Заявитель</th>
                        <td><strong><?= htmlspecialchars($c['applicant']) ?></strong></td>
                    </tr>
                    <tr>
                        <th>№ квартиры / помещения</th>
                        <td><?= htmlspecialchars($c['apartment_number'] ?: '—') ?></td>
                    </tr>
                    <tr>
                        <th>Направление</th>
                        <td>
                            <?php if ($c['direction'] === 'incoming'): ?>
                                <span class="badge bg-warning text-dark">📥 Входящая</span>
                            <?php else: ?>
                                <span class="badge bg-secondary">📤 Исходящая</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th>Откуда поступило / Куда отправлено</th>
                        <td><?= htmlspecialchars($c['received_from'] ?: '—') ?></td>
                    </tr>
                    <tr>
                        <th>Дата поступления</th>
                        <td>
                            <strong><?= $c['received_date'] ? date('d.m.Y', strtotime($c['received_date'])) : '—' ?></strong>
                        </td>
                    </tr>
                    <tr>
                        <th>Получатель (компания)</th>
                        <td>
                            <?php if ($c['recipient_name']): ?>
                                <span class="badge bg-primary fs-6">🏢 <?= htmlspecialchars($c['recipient_name']) ?></span>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th>Предмет претензии</th>
                        <td class="text-wrap"><?= nl2br(htmlspecialchars($c['subject'])) ?></td>
                    </tr>
                    <tr>
                        <th>Текущий статус</th>
                        <td>
                            <span class="badge bg-secondary fs-6">
                                <?= htmlspecialchars($c['current_status'] ?: '—') ?>
                            </span>
                        </td>
                    </tr>
                    <tr>
                        <th>Куда направлен ответ</th>
                        <td><?= htmlspecialchars($c['response_direction'] ?: '—') ?></td>
                    </tr>
                    <tr>
                        <th>Дата ответа</th>
                        <td><?= $c['response_date'] ? date('d.m.Y', strtotime($c['response_date'])) : '—' ?></td>
                    </tr>
                    <tr>
                        <th>Дополнительно</th>
                        <td class="text-wrap"><?= $c['additional_notes'] ? nl2br(htmlspecialchars($c['additional_notes'])) : '—' ?></td>
                    </tr>
                </table>
            </div>
        </div>
    </div>

    <!-- Правая колонка: статус срока и информация -->
    <div class="col-md-5">
        <div class="card shadow-sm border-0 mb-3">
            <div class="card-body text-center py-4">
                <h5 class="fw-bold">⏱ Статус по срокам</h5>
                <div style="font-size:4rem;" class="mb-2">
                    <?php if ($color == 'green'): ?>🟢
                    <?php elseif ($color == 'yellow'): ?>🟡
                    <?php elseif ($color == 'red'): ?>🔴
                    <?php else: ?>🟣
                    <?php endif; ?>
                </div>
                <h4 class="badge badge-<?= $color ?> fs-5 px-3 py-2">
                    <?= getStatusLabel($color) ?>
                </h4>
                <?php if ($answered): ?>
                    <p class="mt-3 mb-1">
                        Ответ дан через:
                        <span class="fs-2 fw-bold text-success"><?= $days ?></span> дн.
                    </p>
                    <p class="mb-0 small text-muted">
                        <i class="bi bi-snow2"></i> Счётчик остановлен — дата ответа указана
                    </p>
                <?php else: ?>
                    <p class="mt-3 mb-0">
                        Дней с даты поступления:
                        <span class="fs-2 fw-bold <?=
                            $color == 'green' ? 'text-success' :
                            ($color == 'yellow' ? 'text-warning' :
                            ($color == 'red' ? 'text-danger' : 'text-purple'))
                        ?>"><?= $days ?></span>
                    </p>
                <?php endif; ?>
                <?php if ($deadline): ?>
                    <p class="mt-3 mb-0 small <?= !$answered && $days >= CLAIM_RESPONSE_DEADLINE_DAYS ? 'text-danger fw-bold' : 'text-muted' ?>">
                        <i class="bi bi-calendar-event"></i>
                        Срок ответа (<?= CLAIM_RESPONSE_DEADLINE_DAYS ?> дн.):
                        до <strong><?= date('d.m.Y', strtotime($deadline)) ?></strong><?= !$answered && $days >= CLAIM_RESPONSE_DEADLINE_DAYS ? ' — истёк' : '' ?>
                    </p>
                <?php endif; ?>
                <?php if ($c['response_file']): ?>
                    <div class="mt-3">
                        <span class="badge bg-success fs-6">✅ Ответ прикреплён</span>
                    </div>
                <?php else: ?>
                    <div class="mt-3">
                        <span class="badge bg-warning text-dark fs-6">⚠️ Ответ не прикреплён</span>
                        <?php if ($days >= 30): ?>
                            <div class="mt-2 text-danger fw-bold">
                                <i class="bi bi-exclamation-triangle"></i>
                                ПРЕТЕНЗИЯ ПРОСРОЧЕНА! Требуется немедленная реакция.
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="card shadow-sm border-0">
            <div class="card-body">
                <h5 class="fw-bold"><i class="bi bi-clock-history"></i> Информация о записи</h5>
                <table class="table table-sm mb-0">
                    <tr>
                        <td class="text-muted">ID записи</td>
                        <td class="fw-bold"><?= $c['id'] ?></td>
                    </tr>
                    <tr>
                        <td class="text-muted">Создана</td>
                        <td><?= date('d.m.Y H:i', strtotime($c['created_at'])) ?></td>
                    </tr>
                    <tr>
                        <td class="text-muted">Обновлена</td>
                        <td><?= date('d.m.Y H:i', strtotime($c['updated_at'])) ?></td>
                    </tr>
                    <tr>
                        <td class="text-muted">Автор</td>
                        <td><?= htmlspecialchars($c['created_by_name'] ?? '—') ?></td>
                    </tr>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Блок файлов -->
<div class="mt-4">
    <h5 class="fw-bold mb-3">
        <i class="bi bi-paperclip"></i> Прикреплённые файлы
        <small class="text-muted">(хранятся на диске)</small>
    </h5>

    <div class="row g-3">
        <?php if ($c['claim_file']): ?>
            <div class="col-md-3">
                <div class="card file-card shadow-sm text-center h-100 border-danger border-opacity-25">
                    <div class="card-body d-flex flex-column align-items-center justify-content-center">
                        <i class="bi bi-file-earmark-pdf fs-1 text-danger mb-2"></i>
                        <h6 class="fw-bold">Претензия</h6>
                        <small class="text-muted text-truncate d-block mb-2" style="max-width:100%;">
                            <?= htmlspecialchars(basename($c['claim_file'])) ?>
                        </small>
                        <a href="<?= uploadLink($c['claim_file']) ?>" target="_blank"
                           class="btn btn-sm btn-danger mt-auto">
                            <i class="bi bi-eye"></i> Открыть файл
                        </a>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($c['response_file']): ?>
            <div class="col-md-3">
                <div class="card file-card shadow-sm text-center h-100 border-success border-opacity-25">
                    <div class="card-body d-flex flex-column align-items-center justify-content-center">
                        <i class="bi bi-file-earmark-pdf fs-1 text-success mb-2"></i>
                        <h6 class="fw-bold">Ответ на претензию</h6>
                        <small class="text-muted text-truncate d-block mb-2" style="max-width:100%;">
                            <?= htmlspecialchars(basename($c['response_file'])) ?>
                        </small>
                        <a href="<?= uploadLink($c['response_file']) ?>" target="_blank"
                           class="btn btn-sm btn-success mt-auto">
                            <i class="bi bi-eye"></i> Открыть файл
                        </a>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <?php if (is_array($additional_files)): ?>
            <?php foreach ($additional_files as $i => $f): ?>
                <div class="col-md-3">
                    <div class="card file-card shadow-sm text-center h-100 border-secondary border-opacity-25">
                        <div class="card-body d-flex flex-column align-items-center justify-content-center">
                            <i class="bi bi-file-earmark fs-1 text-secondary mb-2"></i>
                            <h6 class="fw-bold">Доп. документ <?= $i + 1 ?></h6>
                            <small class="text-muted text-truncate d-block mb-2" style="max-width:100%;">
                                <?= htmlspecialchars(basename($f)) ?>
                            </small>
                            <a href="<?= uploadLink($f) ?>" target="_blank"
                               class="btn btn-sm btn-secondary mt-auto">
                                <i class="bi bi-eye"></i> Открыть файл
                            </a>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>

        <?php if (!$c['claim_file'] && !$c['response_file'] && empty($additional_files)): ?>
            <div class="col-12">
                <div class="text-center text-muted py-5 bg-light rounded">
                    <i class="bi bi-file-earmark-x fs-2 d-block mb-2"></i>
                    <p class="mb-0">Файлы не прикреплены</p>
                    <a href="edit.php?id=<?= $c['id'] ?>" class="btn btn-sm btn-outline-primary mt-2">
                        <i class="bi bi-paperclip"></i> Прикрепить файлы
                    </a>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Блок быстрых действий -->
<div class="mt-4 d-flex gap-2">
    <?php if (hasRole('admin', 'manager')): ?>
    <a href="edit.php?id=<?= $c['id'] ?>" class="btn btn-warning">
        <i class="bi bi-pencil"></i> Редактировать
    </a>
    <?php if (!$c['response_file']): ?>
        <a href="edit.php?id=<?= $c['id'] ?>#response_file" class="btn btn-success">
            <i class="bi bi-reply-all"></i> Прикрепить ответ
        </a>
    <?php endif; ?>
    <a href="delete.php?id=<?= $c['id'] ?>" class="btn btn-danger"
       onclick="return confirm('Удалить претензию №<?= $c['id'] ?>? Это действие необратимо.')">
        <i class="bi bi-trash"></i> Удалить
    </a>
    <?php endif; ?>
    <a href="index.php" class="btn btn-outline-secondary ms-auto">
        <i class="bi bi-arrow-left"></i> Назад к реестру
    </a>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    setTimeout(function() {
        var alerts = document.querySelectorAll('.alert-dismissible');
        alerts.forEach(function(alert) {
            var bsAlert = new bootstrap.Alert(alert);
            bsAlert.close();
        });
    }, 5000);
});
</script>
<?php
require 'templates/footer.php';