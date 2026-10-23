<?php
/**
 * @var string $pageTitle  Заголовок страницы
 * @var array  $user       Массив текущего пользователя
 * @var string $currentPage Текущая страница ('index','dashboard','objects','import','export','users','view','edit','add')
 * @var string $sidebarContent  Доп. HTML внутри sidebar (фильтры для index.php)
 * @var string $extraHead  Доп. теги <style>/<script> конкретной страницы (выводится в <head>)
 */
if (!isset($sidebarContent)) $sidebarContent = '';
if (!isset($extraHead))    $extraHead = '';
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($pageTitle) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link href="assets/style.css" rel="stylesheet">
    <?= $extraHead ?>
</head>
<body>
<div class="container-fluid">
    <div class="row">
        <?php require __DIR__ . '/sidebar.php'; ?>
        <div class="col-md-10 main-content p-4">
