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
    <link href="assets/vendor/bootstrap/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/vendor/bootstrap-icons/bootstrap-icons.min.css">
    <link href="assets/style.css" rel="stylesheet">
    <?= $extraHead ?>
</head>
<body>
<div class="container-fluid">
    <div class="row">
        <?php require __DIR__ . '/sidebar.php'; ?>
        <div class="col-md-10 main-content p-4">
