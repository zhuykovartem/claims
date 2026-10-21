<?php
require 'config.php';

logAction('logout', 'user', $_SESSION['user_id'] ?? null, 'Выход из системы');

// Полная очистка сессии
$_SESSION = [];
session_destroy();

// Удаляем куку сессии
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params['path'], $params['domain'],
        $params['secure'], $params['httponly']
    );
}

header('Location: login.php');
exit;