<?php
/**
 * @var string $currentPage
 * @var string $sidebarContent
 * @var array  $user
 */
$navLinks = [
    'index'     => ['Реестр претензий', 'bi-list-ul'],
    'dashboard' => ['Дашборд и графики', 'bi-speedometer2'],
    'objects'   => ['Компании и объекты', 'bi-building'],
];
?>
        <div class="col-md-2 sidebar p-3 d-flex flex-column">
            <div class="text-center mb-4">
                <i class="bi bi-file-earmark-text fs-2"></i>
                <h6 class="fw-bold mt-2">Реестр претензий</h6>
                <small class="text-muted"><i class="bi bi-person-circle"></i> <?= htmlspecialchars($user['full_name'] ?? $user['username']) ?></small>
            </div>

            <h6 class="sidebar-label"><i class="bi bi-file-text"></i> Разделы</h6>
            <div class="nav flex-column mb-3">
<?php foreach ($navLinks as $page => [$label, $icon]): ?>
                <a href="<?= $page ?>.php" class="nav-link<?= $currentPage === $page ? ' active' : '' ?>"><i class="bi <?= $icon ?>"></i> <?= $label ?></a>
<?php endforeach; ?>
            </div>

            <?= $sidebarContent ?>

            <div class="mt-auto">
                <hr class="sidebar-divider">
                <a href="logout.php" class="btn btn-outline-danger w-100 btn-sm"><i class="bi bi-box-arrow-right"></i> Выйти</a>
            </div>
        </div>
