<?php
session_start();
require_once __DIR__ . '/db.php';

// Mark user offline immediately before clearing session
if (!empty($_SESSION['user_id'])) {
    try {
        $stmt = $pdo->prepare('UPDATE users SET last_seen = NULL WHERE id = ?');
        $stmt->execute([$_SESSION['user_id']]);
    } catch (Throwable $e) {
        // Ignore if last_seen column does not exist yet
    }
}

// Clear session data
$_SESSION = [];

// Delete the session cookie
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params['path'],
        $params['domain'],
        $params['secure'],
        $params['httponly']
    );
}

// Destroy session and redirect to homepage
session_destroy();

header('Location: index.php');
exit;