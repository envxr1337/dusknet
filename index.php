<?php
session_start();
require_once __DIR__ . '/db.php';

// Generate CSRF token so the sidebar login form works with login.php
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$isLoggedIn = !empty($_SESSION['user_id']);
$onlineUsers = [];
$onlineCount = 0;
$totalMembers = 0;
$newMembers = [];
$myAvatar = '';
$pendingFriendRequests = [];
$pendingWallCount = 0;
$userNotifications = [];
$unreadNotifCount = 0;
$notifFeedback = '';

$publicRooms = [
    ['id' => 'lounge', 'name' => '#lounge', 'topic' => 'General daily chat', 'users' => 0],
    ['id' => 'music',  'name' => '#music',  'topic' => 'Songs, albums & radio', 'users' => 0],
    ['id' => 'games',  'name' => '#games',  'topic' => 'PC, retro & indie games', 'users' => 0],
];

$searchQuery = trim($_GET['q'] ?? '');
$foundUsers = [];
$foundRooms = [];

/**
 * Renders the blue admin checkmark (with "Admin" hover tooltip)
 * and the Official Main Account tag if the username is "dusknet".
 */
function renderUserBadges(string $username, string $role = 'user'): string
{
    $html = '';
    $r = strtolower(trim($role));

    if ($r === 'admin') {
        $html .= '<span class="admin-tick" data-tip="Admin" aria-label="Admin">'
            . '<svg viewBox="0 0 16 16" width="12" height="12" aria-hidden="true">'
            . '<circle cx="8" cy="8" r="7.5" fill="#1d74e6"/>'
            . '<path d="M4.6 8.3l2.2 2.2 4.6-4.8" fill="none" stroke="#ffffff" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>'
            . '</svg>'
            . '</span>';
    } elseif ($r === 'mod') {
        $html .= '<span class="role-pill role-mod" title="Moderator">MOD</span>';
    } elseif ($r === 'vip') {
        $html .= '<span class="role-pill role-vip" title="VIP Member">VIP</span>';
    }

    if (strtolower(trim($username)) === 'dusknet') {
        $html .= '<span class="official-badge" title="Official Main Account">Official Account</span>';
    }

    return $html;
}

/**
 * Returns a safe relative avatar path if the file exists inside /avatars/.
 */
function getSafeAvatar(?string $avatar): string
{
    $avatar = trim((string) $avatar);
    if ($avatar !== '' && !str_contains($avatar, '..') && is_file(__DIR__ . '/' . $avatar)) {
        return $avatar;
    }
    return '';
}

try {
    // Purge expired 24-hour temporary accounts if expires_at column exists
    try {
        $pdo->exec('DELETE FROM users WHERE expires_at IS NOT NULL AND expires_at <= NOW()');
    } catch (Throwable $e) {
        // Ignore if expires_at column does not exist yet
    }

    // Ensure 'last_seen' column exists in users table for online tracking
    $colCheck = $pdo->query("SHOW COLUMNS FROM users LIKE 'last_seen'")->fetch();
    if (!$colCheck) {
        $pdo->exec('ALTER TABLE users ADD COLUMN last_seen TIMESTAMP NULL DEFAULT NULL');
    }

    $hasAvatarCol = (bool) $pdo->query("SHOW COLUMNS FROM users LIKE 'avatar'")->fetch();

    // Ensure last_notified_role exists to detect role upgrades (VIP / Mod / Admin)
    $hasNotifRoleCol = (bool) $pdo->query("SHOW COLUMNS FROM users LIKE 'last_notified_role'")->fetch();
    if (!$hasNotifRoleCol) {
        $pdo->exec('ALTER TABLE users ADD COLUMN last_notified_role VARCHAR(20) NULL DEFAULT NULL');
        $pdo->exec('UPDATE users SET last_notified_role = role WHERE last_notified_role IS NULL');
    }

    // Ensure friendships & notifications tables exist
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS friendships (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            requester_id INT UNSIGNED NOT NULL,
            addressee_id INT UNSIGNED NOT NULL,
            status ENUM('pending', 'accepted') NOT NULL DEFAULT 'pending',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_friend_pair (requester_id, addressee_id),
            INDEX idx_addressee (addressee_id, status),
            CONSTRAINT fk_friend_req FOREIGN KEY (requester_id) REFERENCES users(id) ON DELETE CASCADE,
            CONSTRAINT fk_friend_add FOREIGN KEY (addressee_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS notifications (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            type VARCHAR(30) NOT NULL DEFAULT 'system',
            message VARCHAR(255) NOT NULL,
            link VARCHAR(255) NULL DEFAULT NULL,
            is_read TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_notif_user (user_id, is_read, created_at),
            CONSTRAINT fk_notif_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    // Update last_seen and sync latest username/role/avatar from DB
    if ($isLoggedIn) {
        $myId = (int) $_SESSION['user_id'];

        $touch = $pdo->prepare('UPDATE users SET last_seen = NOW() WHERE id = ?');
        $touch->execute([$myId]);

        $meSql = $hasAvatarCol
            ? 'SELECT username, role, avatar, last_notified_role FROM users WHERE id = ? LIMIT 1'
            : 'SELECT username, role, last_notified_role FROM users WHERE id = ? LIMIT 1';
        $meStmt = $pdo->prepare($meSql);
        $meStmt->execute([$myId]);
        $me = $meStmt->fetch();
        if ($me) {
            $_SESSION['username'] = $me['username'];
            $_SESSION['role'] = $me['role'];
            $myAvatar = getSafeAvatar($me['avatar'] ?? '');

            // Detect role changes (e.g., promoted to VIP, Mod, or Admin) and create a level-up notification
            $currentRole = strtolower(trim($me['role'] ?? 'user'));
            $prevRole = $me['last_notified_role'] !== null ? strtolower(trim($me['last_notified_role'])) : null;

            if ($prevRole === null) {
                $pdo->prepare('UPDATE users SET last_notified_role = ? WHERE id = ?')->execute([$currentRole, $myId]);
            } elseif ($prevRole !== $currentRole) {
                $roleMessages = [
                    'vip'   => 'Level Up! You have been upgraded to VIP! Animated GIF avatars are now unlocked.',
                    'mod'   => 'Level Up! You have been promoted to Moderator on DuskNet!',
                    'admin' => 'Level Up! You are now an Administrator! Your blue Admin checkmark is active.',
                    'user'  => 'Your account role has been updated to Member.',
                ];
                $notifType = in_array($currentRole, ['vip', 'mod', 'admin'], true) ? 'level_up' : 'system';
                $notifMsg = $roleMessages[$currentRole] ?? ('Your account role has been updated to ' . ucfirst($currentRole) . '.');

                $pdo->prepare('INSERT INTO notifications (user_id, type, message, link) VALUES (?, ?, ?, ?)')
                    ->execute([$myId, $notifType, $notifMsg, 'account.php']);
                $pdo->prepare('UPDATE users SET last_notified_role = ? WHERE id = ?')
                    ->execute([$currentRole, $myId]);
            }
        }

        // Handle notification & friend request actions submitted from homepage
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['notif_action'])) {
            $token = $_POST['csrf_token'] ?? '';
            $nAction = $_POST['notif_action'];

            if (hash_equals($_SESSION['csrf_token'], $token)) {
                if ($nAction === 'accept_friend') {
                    $reqId = (int) ($_POST['requester_id'] ?? 0);
                    $upd = $pdo->prepare(
                        "UPDATE friendships SET status = 'accepted' WHERE requester_id = ? AND addressee_id = ? AND status = 'pending'"
                    );
                    $upd->execute([$reqId, $myId]);
                    if ($upd->rowCount() > 0) {
                        // Notify the sender that their friend request was accepted
                        $pdo->prepare('INSERT INTO notifications (user_id, type, message, link) VALUES (?, ?, ?, ?)')
                            ->execute([
                                $reqId,
                                'friend_accept',
                                $_SESSION['username'] . ' accepted your friend request!',
                                'profile.php?u=' . urlencode($_SESSION['username']),
                            ]);
                        $notifFeedback = 'Friend request accepted!';
                    }
                } elseif ($nAction === 'decline_friend') {
                    $reqId = (int) ($_POST['requester_id'] ?? 0);
                    $del = $pdo->prepare(
                        "DELETE FROM friendships WHERE requester_id = ? AND addressee_id = ? AND status = 'pending'"
                    );
                    $del->execute([$reqId, $myId]);
                    if ($del->rowCount() > 0) {
                        $notifFeedback = 'Friend request declined.';
                    }
                } elseif ($nAction === 'mark_all_read') {
                    $pdo->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0')->execute([$myId]);
                    $notifFeedback = 'All notifications marked as read.';
                } elseif ($nAction === 'dismiss_notif') {
                    $nid = (int) ($_POST['notif_id'] ?? 0);
                    if ($nid > 0) {
                        $pdo->prepare('DELETE FROM notifications WHERE id = ? AND user_id = ?')->execute([$nid, $myId]);
                    }
                }
            }
        }

        // Load incoming pending friend requests
        $frReqSql = $hasAvatarCol
            ? "SELECT u.id, u.username, u.role, u.avatar, f.created_at
               FROM friendships f
               JOIN users u ON u.id = f.requester_id
               WHERE f.addressee_id = ? AND f.status = 'pending'
               ORDER BY f.created_at DESC"
            : "SELECT u.id, u.username, u.role, f.created_at
               FROM friendships f
               JOIN users u ON u.id = f.requester_id
               WHERE f.addressee_id = ? AND f.status = 'pending'
               ORDER BY f.created_at DESC";
        $frReqStmt = $pdo->prepare($frReqSql);
        $frReqStmt->execute([$myId]);
        $pendingFriendRequests = $frReqStmt->fetchAll();

        // Check pending profile wall comments awaiting owner approval
        try {
            $pwStmt = $pdo->prepare(
                "SELECT COUNT(*) FROM profile_wall WHERE profile_user_id = ? AND status = 'pending'"
            );
            $pwStmt->execute([$myId]);
            $pendingWallCount = (int) $pwStmt->fetchColumn();
        } catch (Throwable $e) {
            $pendingWallCount = 0;
        }

        // Load latest notifications for this user
        $nStmt = $pdo->prepare(
            'SELECT id, type, message, link, is_read, created_at
             FROM notifications
             WHERE user_id = ?
             ORDER BY is_read ASC, created_at DESC
             LIMIT 12'
        );
        $nStmt->execute([$myId]);
        $userNotifications = $nStmt->fetchAll();

        // Count total unread/actionable notifications
        $unreadSystemCount = 0;
        foreach ($userNotifications as $un) {
            if ((int) $un['is_read'] === 0) {
                $unreadSystemCount++;
            }
        }
        $unreadNotifCount = count($pendingFriendRequests) + ($pendingWallCount > 0 ? 1 : 0) + $unreadSystemCount;
    }

    // Fetch users active within the last 5 minutes
    $onlineStmt = $pdo->query(
        'SELECT username, role FROM users WHERE last_seen >= (NOW() - INTERVAL 5 MINUTE) ORDER BY last_seen DESC'
    );
    $onlineUsers = $onlineStmt->fetchAll();
    $onlineCount = count($onlineUsers);

    // Fetch newest registered members (with avatars) for the bottom showcase
    $newMembersSql = $hasAvatarCol
        ? 'SELECT username, role, avatar, created_at FROM users ORDER BY created_at DESC LIMIT 8'
        : 'SELECT username, role, created_at FROM users ORDER BY created_at DESC LIMIT 8';
    $newMembers = $pdo->query($newMembersSql)->fetchAll();

    // Fetch total registered member count
    $totalMembers = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();

    // Handle header search for users and rooms
    if ($searchQuery !== '') {
        $searchStmt = $pdo->prepare(
            'SELECT username, role FROM users WHERE username LIKE ? ORDER BY username ASC LIMIT 15'
        );
        $searchStmt->execute(['%' . $searchQuery . '%']);
        $foundUsers = $searchStmt->fetchAll();

        $needle = strtolower($searchQuery);
        foreach ($publicRooms as $room) {
            if (strpos(strtolower($room['name'] . ' ' . $room['topic']), $needle) !== false) {
                $foundRooms[] = $room;
            }
        }
    }
} catch (Throwable $e) {
    // Keep page functional even if a query fails
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="blue">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DuskNet - Classic Chat Community</title>
    <style>
        /* Theme Variables - Default: Classic 2003 Blue */
        :root,
        [data-theme="blue"] {
            --bg-page: #d0d7e1;
            --bg-pattern: rgba(255, 255, 255, 0.25);
            --bg-wrapper: #f7f9fc;
            --bg-box: #ffffff;
            --bg-soft: #e6ecf4;
            --bg-hover: #eef3fa;
            --border: #b8c5d6;
            --border-strong: #6f86a3;
            --header-top: #376799;
            --header-bot: #1f446b;
            --header-border: #16314f;
            --title-top: #4b79a8;
            --title-bot: #315a85;
            --text: #1a1a1a;
            --text-muted: #555555;
            --link: #144987;
            --input-bg: #ffffff;
            --input-border: #7f9db9;
            --btn-top: #f7f7f7;
            --btn-bot: #dcdcdc;
            --btn-text: #111111;
            --btn-primary-top: #3d72a8;
            --btn-primary-bot: #245080;
            --btn-primary-border: #193b61;
            --notice-bg: #fffbe6;
            --notice-border: #d6c88d;
            --role-admin: #c42323;
        }

        /* Theme: Dusk (Old Slate & Rust) */
        [data-theme="dusk"] {
            --bg-page: #cfc9c2;
            --bg-pattern: rgba(255, 255, 255, 0.2);
            --bg-wrapper: #f6f4f0;
            --bg-box: #ffffff;
            --bg-soft: #eae5de;
            --bg-hover: #f3eee8;
            --border: #bfb6ac;
            --border-strong: #7d7369;
            --header-top: #4f4654;
            --header-bot: #302a33;
            --header-border: #201c22;
            --title-top: #635869;
            --title-bot: #473e4c;
            --text: #1e1b1e;
            --text-muted: #5c5559;
            --link: #8c3d19;
            --input-bg: #ffffff;
            --input-border: #8f8479;
            --btn-top: #f4f1ec;
            --btn-bot: #d9d3ca;
            --btn-text: #1e1b1e;
            --btn-primary-top: #b85d2b;
            --btn-primary-bot: #8f431a;
            --btn-primary-border: #692f10;
            --notice-bg: #f8f0e3;
            --notice-border: #cfb693;
            --role-admin: #b82525;
        }

        /* Theme: Classic Dark */
        [data-theme="dark"] {
            --bg-page: #14171c;
            --bg-pattern: rgba(255, 255, 255, 0.03);
            --bg-wrapper: #1e2229;
            --bg-box: #252a33;
            --bg-soft: #2b313b;
            --bg-hover: #323945;
            --border: #3d4554;
            --border-strong: #535e70;
            --header-top: #263547;
            --header-bot: #16202b;
            --header-border: #0e151c;
            --title-top: #34475e;
            --title-bot: #243345;
            --text: #dcdcdc;
            --text-muted: #9aa3b0;
            --link: #7cb4eb;
            --input-bg: #181c22;
            --input-border: #535e70;
            --btn-top: #38404d;
            --btn-bot: #262c36;
            --btn-text: #e0e0e0;
            --btn-primary-top: #346394;
            --btn-primary-bot: #22456b;
            --btn-primary-border: #17304d;
            --notice-bg: #2a2820;
            --notice-border: #5c5439;
            --role-admin: #ff6b6b;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            padding: 12px 8px;
            background-color: var(--bg-page);
            background-image: repeating-linear-gradient(
                0deg,
                var(--bg-pattern) 0px,
                var(--bg-pattern) 1px,
                transparent 1px,
                transparent 4px
            );
            color: var(--text);
            font-family: Verdana, Tahoma, Arial, sans-serif;
            font-size: 11px;
            line-height: 1.45;
        }

        a {
            color: var(--link);
            text-decoration: none;
        }

        a:hover {
            text-decoration: underline;
        }

        .wrapper {
            max-width: 780px;
            margin: 0 auto;
            background-color: var(--bg-wrapper);
            border: 1px solid var(--border-strong);
        }

        /* Header */
        .header {
            background: linear-gradient(to bottom, var(--header-top), var(--header-bot));
            color: #ffffff;
            padding: 11px 14px;
            border-bottom: 2px solid var(--header-border);
            display: flex;
            flex-wrap: wrap;
            justify-content: space-between;
            align-items: center;
            gap: 8px;
        }

        .brand {
            font-size: 18px;
            font-weight: bold;
            color: #ffffff;
        }

        .tagline {
            font-size: 10px;
            color: #d6e0ec;
            margin-top: 1px;
        }

        .header-search {
            display: flex;
            align-items: center;
            gap: 4px;
        }

        .header-search input[type="text"] {
            width: 175px;
            padding: 3px 6px;
            border: 1px solid var(--header-border);
            background-color: var(--input-bg);
            color: var(--text);
            font-family: inherit;
            font-size: 11px;
        }

        .header-search input[type="text"]:focus {
            outline: none;
        }

        .header-search button {
            background: linear-gradient(to bottom, var(--btn-top), var(--btn-bot));
            color: var(--btn-text);
            border: 1px solid var(--header-border);
            padding: 3px 8px;
            font-family: inherit;
            font-size: 11px;
            font-weight: bold;
            cursor: pointer;
        }

        .header-search button:hover {
            filter: brightness(0.96);
        }

        .header-right {
            text-align: right;
            font-size: 11px;
        }

        .header-right a {
            color: #ffffff;
        }

        .sep {
            color: #9bb1c9;
            margin: 0 4px;
        }

        .clock-bar {
            font-size: 10px;
            color: #d6e0ec;
            margin-top: 3px;
        }

        /* Navigation & Theme Switcher Bar */
        .navbar {
            background-color: var(--bg-soft);
            border-bottom: 1px solid var(--border-strong);
            padding: 5px 14px;
            display: flex;
            flex-wrap: wrap;
            justify-content: space-between;
            align-items: center;
            gap: 8px;
            font-size: 11px;
        }

        .nav-links {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 6px;
        }

        .nav-links a {
            color: var(--link);
            font-weight: bold;
        }

        .nav-sep {
            color: var(--border-strong);
        }

        .theme-switcher {
            display: flex;
            align-items: center;
            gap: 4px;
            font-size: 10px;
            color: var(--text-muted);
        }

        .theme-btn {
            background: linear-gradient(to bottom, var(--btn-top), var(--btn-bot));
            color: var(--btn-text);
            border: 1px solid var(--border-strong);
            padding: 1px 6px;
            font-family: inherit;
            font-size: 10px;
            cursor: pointer;
        }

        .theme-btn.active {
            background: linear-gradient(to bottom, var(--btn-primary-top), var(--btn-primary-bot));
            color: #ffffff;
            border-color: var(--btn-primary-border);
        }

        /* Dismissible Announcement Strip */
        .notice-bar {
            background-color: var(--notice-bg);
            border-bottom: 1px solid var(--notice-border);
            padding: 5px 14px;
            font-size: 11px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 10px;
        }

        .notice-close {
            background: none;
            border: none;
            color: var(--text-muted);
            font-size: 13px;
            font-weight: bold;
            line-height: 1;
            cursor: pointer;
            padding: 0 3px;
        }

        .notice-close:hover {
            color: var(--text);
        }

        /* Main Two-Column Layout */
        .content {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            padding: 12px;
        }

        .main-col {
            flex: 1 1 450px;
            min-width: 0;
        }

        .side-col {
            flex: 0 1 235px;
            min-width: 215px;
            margin: 0 auto;
        }

        /* Classic Boxes */
        .box {
            border: 1px solid var(--border-strong);
            background-color: var(--bg-box);
            margin-bottom: 12px;
        }

        .box-title {
            background: linear-gradient(to bottom, var(--title-top), var(--title-bot));
            color: #ffffff;
            font-weight: bold;
            font-size: 11px;
            padding: 4px 8px;
            border-bottom: 1px solid var(--border-strong);
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 8px;
        }

        .box-body {
            padding: 9px 10px;
        }

        /* Classic Room Filter Input */
        .room-filter {
            width: 115px;
            padding: 1px 4px;
            font-size: 10px;
            font-family: inherit;
            border: 1px solid var(--header-border);
            background-color: var(--input-bg);
            color: var(--text);
        }

        .room-filter:focus {
            outline: none;
        }

        /* Rooms Table */
        table.rooms {
            width: 100%;
            border-collapse: collapse;
            font-size: 11px;
        }

        table.rooms th {
            background-color: var(--bg-soft);
            border-bottom: 1px solid var(--border);
            text-align: left;
            padding: 5px 8px;
            color: var(--text);
        }

        table.rooms td {
            padding: 5px 8px;
            border-bottom: 1px solid var(--border);
        }

        table.rooms tbody tr:hover td {
            background-color: var(--bg-hover);
        }

        table.rooms tr:last-child td {
            border-bottom: none;
        }

        /* Forms & Classic Bevel Buttons */
        .form-group {
            margin-bottom: 8px;
        }

        .form-group label {
            display: block;
            font-size: 11px;
            font-weight: bold;
            margin-bottom: 2px;
        }

        .form-group input[type="text"],
        .form-group input[type="password"] {
            width: 100%;
            padding: 3px 5px;
            border: 1px solid var(--input-border);
            background-color: var(--input-bg);
            color: var(--text);
            font-family: inherit;
            font-size: 11px;
        }

        .form-group input:focus {
            outline: none;
            border-color: var(--header-top);
        }

        .btn {
            display: inline-block;
            background: linear-gradient(to bottom, var(--btn-top), var(--btn-bot));
            color: var(--btn-text);
            border: 1px solid var(--border-strong);
            box-shadow: inset 1px 1px 0 rgba(255, 255, 255, 0.5);
            padding: 3px 10px;
            font-family: inherit;
            font-size: 11px;
            font-weight: bold;
            cursor: pointer;
        }

        .btn:hover {
            text-decoration: none;
            filter: brightness(0.97);
        }

        .btn-primary {
            background: linear-gradient(to bottom, var(--btn-primary-top), var(--btn-primary-bot));
            color: #ffffff;
            border-color: var(--btn-primary-border);
            box-shadow: inset 1px 1px 0 rgba(255, 255, 255, 0.25);
        }

        .empty-note {
            font-size: 11px;
            color: var(--text-muted);
            font-style: italic;
        }

        .online-list {
            line-height: 1.6;
        }

        .role-tag {
            font-size: 9px;
            color: var(--text-muted);
            text-transform: uppercase;
        }

        .role-admin {
            color: var(--role-admin);
            font-weight: bold;
        }

        .role-pill {
            display: inline-block;
            margin-left: 3px;
            padding: 0 4px;
            font-size: 9px;
            font-weight: bold;
            border: 1px solid;
            vertical-align: 1px;
        }

        .role-mod {
            background-color: #e6f4ea;
            color: #1b6e2f;
            border-color: #6ab07c;
        }

        .role-vip {
            background-color: #fff4d6;
            color: #946200;
            border-color: #d4a638;
        }

        /* Logged-in User Avatar in Sidebar */
        .my-profile-head {
            display: flex;
            align-items: center;
            gap: 9px;
            padding-bottom: 8px;
            margin-bottom: 6px;
            border-bottom: 1px dotted var(--border);
        }

        .my-avatar-link {
            width: 52px;
            height: 52px;
            border: 1px solid var(--border-strong);
            background-color: var(--bg-soft);
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            flex-shrink: 0;
            font-size: 18px;
            font-weight: bold;
            color: var(--text-muted);
            text-decoration: none;
        }

        .my-avatar-link:hover {
            text-decoration: none;
            border-color: var(--link);
        }

        .my-avatar-link img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }

        .my-profile-meta {
            min-width: 0;
            flex: 1;
        }

        /* New Members Grid */
        .new-members-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 8px;
        }

        @media (max-width: 520px) {
            .new-members-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        .member-card {
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
            padding: 6px 4px;
            border: 1px solid var(--border);
            background-color: var(--bg-soft);
            text-decoration: none;
            color: var(--text);
        }

        .member-card:hover {
            background-color: var(--bg-hover);
            border-color: var(--border-strong);
            text-decoration: none;
        }

        .member-avatar {
            width: 64px;
            height: 64px;
            border: 1px solid var(--border-strong);
            background-color: var(--bg-box);
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            margin-bottom: 5px;
            font-size: 20px;
            font-weight: bold;
            color: var(--text-muted);
        }

        .member-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }

        .member-name {
            font-size: 11px;
            font-weight: bold;
            color: var(--link);
            max-width: 100%;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .member-card:hover .member-name {
            text-decoration: underline;
        }

        .member-badges {
            margin-top: 2px;
            line-height: 1.2;
        }

        .admin-tick {
            position: relative;
            display: inline-flex;
            align-items: center;
            vertical-align: -2px;
            margin-left: 3px;
            cursor: default;
        }

        .admin-tick:hover::after {
            content: attr(data-tip);
            position: absolute;
            bottom: 135%;
            left: 50%;
            transform: translateX(-50%);
            background-color: #1a1a1a;
            color: #ffffff;
            border: 1px solid #000000;
            padding: 1px 5px;
            font-size: 9px;
            font-weight: normal;
            white-space: nowrap;
            z-index: 20;
            pointer-events: none;
        }

        .official-badge {
            display: inline-block;
            margin-left: 3px;
            padding: 0 4px;
            font-size: 9px;
            font-weight: bold;
            background-color: var(--bg-soft);
            color: var(--link);
            border: 1px solid var(--border-strong);
            white-space: nowrap;
            vertical-align: 1px;
        }

        .header .official-badge {
            background-color: rgba(255, 255, 255, 0.16);
            color: #ffffff;
            border-color: rgba(255, 255, 255, 0.45);
        }

        .account-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            margin-top: 8px;
        }

        .stats-list {
            list-style: none;
            margin: 0;
            padding: 0;
            font-size: 11px;
        }

        .stats-list li {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 8px;
            padding: 4px 0;
            border-bottom: 1px dotted var(--border);
        }

        .stats-label {
            flex-shrink: 0;
        }

        .stats-value {
            text-align: right;
            display: flex;
            flex-wrap: wrap;
            justify-content: flex-end;
            align-items: center;
            gap: 3px;
        }

        .stats-value .official-badge {
            margin-left: 0;
        }

        .search-group-title {
            font-weight: bold;
            color: var(--text-muted);
            font-size: 10px;
            text-transform: uppercase;
            margin: 6px 0 3px;
        }

        .search-group-title:first-child {
            margin-top: 0;
        }

        .stats-list li:last-child {
            border-bottom: none;
        }

        /* Notifications Box */
        .notif-count-pill {
            display: inline-block;
            margin-left: 3px;
            padding: 0 4px;
            font-size: 9px;
            font-weight: bold;
            background-color: #c42323;
            color: #ffffff;
            border: 1px solid #7d1111;
            vertical-align: 1px;
        }

        .notif-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 8px;
            padding: 6px 8px;
            border-bottom: 1px dotted var(--border);
        }

        .notif-item:last-child {
            border-bottom: none;
        }

        .notif-item.unread {
            background-color: var(--bg-soft);
        }

        .notif-item.levelup {
            background-color: var(--notice-bg);
            border-left: 3px solid #d4a638;
        }

        .notif-main {
            display: flex;
            align-items: center;
            gap: 8px;
            flex: 1;
            min-width: 190px;
        }

        .notif-avatar {
            width: 30px;
            height: 30px;
            border: 1px solid var(--border-strong);
            background-color: var(--bg-box);
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            flex-shrink: 0;
            font-size: 11px;
            font-weight: bold;
            color: var(--text-muted);
            text-decoration: none;
        }

        .notif-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }

        .notif-tag {
            display: inline-block;
            padding: 0 4px;
            margin-right: 4px;
            font-size: 9px;
            font-weight: bold;
            border: 1px solid var(--border-strong);
            background-color: var(--bg-box);
            color: var(--link);
            vertical-align: 1px;
        }

        .notif-tag.tag-levelup {
            background-color: #fff4d6;
            color: #8a5a00;
            border-color: #d4a638;
        }

        .notif-tag.tag-friend {
            background-color: #e6f4ea;
            color: #1b6e2f;
            border-color: #6ab07c;
        }

        .notif-time {
            font-size: 9px;
            color: var(--text-muted);
            margin-top: 2px;
        }

        .notif-actions {
            display: flex;
            align-items: center;
            gap: 4px;
            flex-shrink: 0;
        }

        .btn-xs {
            padding: 2px 7px;
            font-size: 10px;
        }

        .btn-approve {
            background: linear-gradient(to bottom, #489957, #2e6e3a);
            color: #ffffff;
            border-color: #1f4f28;
            box-shadow: inset 1px 1px 0 rgba(255, 255, 255, 0.25);
        }

        .notif-feedback {
            background-color: var(--notice-bg);
            border-bottom: 1px solid var(--notice-border);
            padding: 5px 8px;
            font-size: 11px;
            font-weight: bold;
        }

        .footer {
            background-color: var(--bg-soft);
            border-top: 1px solid var(--border-strong);
            text-align: center;
            padding: 8px;
            font-size: 10px;
            color: var(--text-muted);
        }
    </style>
</head>
<body>

    <div class="wrapper">

        <!-- Header -->
        <header class="header">
            <div>
                <a href="index.php" class="brand" style="text-decoration: none;">DuskNet</a>
                <div class="tagline">Public web chat rooms</div>
            </div>
            <form action="index.php" method="get" class="header-search">
                <input
                    type="text"
                    name="q"
                    value="<?= htmlspecialchars($searchQuery, ENT_QUOTES, 'UTF-8') ?>"
                    placeholder="Search users or rooms..."
                    aria-label="Search users or rooms"
                >
                <button type="submit">Search</button>
            </form>
            <div class="header-right">
                <div>
                    <?php if ($isLoggedIn): ?>
                        <strong><?= htmlspecialchars($_SESSION['username'], ENT_QUOTES, 'UTF-8') ?></strong><?= renderUserBadges($_SESSION['username'], $_SESSION['role'] ?? 'user') ?><span class="sep">|</span><a href="#notifications-box">Notifications<?php if ($unreadNotifCount > 0): ?><span class="notif-count-pill"><?= $unreadNotifCount ?></span><?php endif; ?></a><span class="sep">|</span><a href="account.php">My Account</a><span class="sep">|</span><a href="logout.php">Logout</a>
                    <?php else: ?>
                        <a href="login.php">Login</a><span class="sep">|</span><a href="register.php">Sign Up</a><span class="sep">|</span><a href="help.php">Help</a>
                    <?php endif; ?>
                </div>
                <div class="clock-bar">
                    Time: <span id="sessionTimer">00:00:01</span>
                </div>
            </div>
        </header>

        <!-- Navigation & Theme Switcher -->
        <nav class="navbar">
            <div class="nav-links">
                <a href="index.php">Home</a>
                <span class="nav-sep">|</span>
                <a href="rooms.php">Chat Rooms</a>
                <span class="nav-sep">|</span>
                <a href="members.php">Members</a>
                <span class="nav-sep">|</span>
                <a href="rules.php">Rules</a>
                <span class="nav-sep">|</span>
                <?php if ($isLoggedIn): ?>
                    <a href="account.php">My Account</a>
                <?php else: ?>
                    <a href="register.php">Register</a>
                <?php endif; ?>
            </div>
            <div class="theme-switcher">
                <span>Theme:</span>
                <button type="button" class="theme-btn active" data-set-theme="blue">Blue</button>
                <button type="button" class="theme-btn" data-set-theme="dusk">Dusk</button>
                <button type="button" class="theme-btn" data-set-theme="dark">Dark</button>
            </div>
        </nav>

        <!-- Dismissible Announcement Bar -->
        <div class="notice-bar" id="noticeBar">
            <span><strong>Notice:</strong> Welcome to DuskNet. Please read the rules before joining a room.</span>
            <button type="button" class="notice-close" id="closeNotice" aria-label="Close notice">&times;</button>
        </div>

        <!-- Main Content -->
        <main class="content">

            <!-- Left Column -->
            <div class="main-col">

                <?php if ($searchQuery !== ''): ?>
                    <!-- Search Results Box -->
                    <section class="box">
                        <div class="box-title">
                            <span>Search Results: "<?= htmlspecialchars($searchQuery, ENT_QUOTES, 'UTF-8') ?>"</span>
                            <a href="index.php" style="color: #ffffff; font-weight: normal; font-size: 10px;">[Clear]</a>
                        </div>
                        <div class="box-body">
                            <?php if (empty($foundRooms) && empty($foundUsers)): ?>
                                <span class="empty-note">No matching users or rooms found.</span>
                            <?php else: ?>
                                <?php if (!empty($foundRooms)): ?>
                                    <div class="search-group-title">Rooms (<?= count($foundRooms) ?>)</div>
                                    <div class="online-list">
                                        <?php foreach ($foundRooms as $r): ?>
                                            <div>
                                                <strong><?= htmlspecialchars($r['name'], ENT_QUOTES, 'UTF-8') ?></strong>
                                                &ndash; <?= htmlspecialchars($r['topic'], ENT_QUOTES, 'UTF-8') ?>
                                                (<a href="room.php?id=<?= urlencode($r['id']) ?>">Join &raquo;</a>)
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>

                                <?php if (!empty($foundUsers)): ?>
                                    <div class="search-group-title">Users (<?= count($foundUsers) ?>)</div>
                                    <div class="online-list">
                                        <?php
                                        $userLinks = [];
                                        foreach ($foundUsers as $u) {
                                            $safeName = htmlspecialchars($u['username'], ENT_QUOTES, 'UTF-8');
                                            $userLinks[] = '<a href="profile.php?u=' . urlencode($u['username']) . '"><strong>' . $safeName . '</strong></a>'
                                                . renderUserBadges($u['username'], $u['role']);
                                        }
                                        echo implode(', ', $userLinks);
                                        ?>
                                    </div>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </section>
                <?php endif; ?>

                <?php if ($isLoggedIn): ?>
                    <!-- Notifications Box (Friend Requests, Level-Up Role Upgrades, Wall Approvals & Alerts) -->
                    <section class="box" id="notifications-box">
                        <div class="box-title">
                            <span>
                                Notifications
                                <?php if ($unreadNotifCount > 0): ?>
                                    (<?= $unreadNotifCount ?> new)
                                <?php endif; ?>
                            </span>
                            <?php if (!empty($userNotifications)): ?>
                                <form action="index.php#notifications-box" method="post" style="margin: 0;">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="notif_action" value="mark_all_read">
                                    <button type="submit" class="btn btn-xs">Mark All Read</button>
                                </form>
                            <?php endif; ?>
                        </div>

                        <?php if ($notifFeedback !== ''): ?>
                            <div class="notif-feedback">
                                <?= htmlspecialchars($notifFeedback, ENT_QUOTES, 'UTF-8') ?>
                            </div>
                        <?php endif; ?>

                        <?php if (empty($pendingFriendRequests) && $pendingWallCount === 0 && empty($userNotifications)): ?>
                            <div class="box-body">
                                <span class="empty-note">No new notifications right now.</span>
                            </div>
                        <?php else: ?>
                            <div>
                                <!-- 1. Pending Friend Requests with Accept / Decline -->
                                <?php foreach ($pendingFriendRequests as $req): ?>
                                    <?php $reqAvatar = getSafeAvatar($req['avatar'] ?? ''); ?>
                                    <div class="notif-item unread">
                                        <div class="notif-main">
                                            <a href="profile.php?u=<?= urlencode($req['username']) ?>" class="notif-avatar">
                                                <?php if ($reqAvatar !== ''): ?>
                                                    <img src="<?= htmlspecialchars($reqAvatar, ENT_QUOTES, 'UTF-8') ?>" alt="">
                                                <?php else: ?>
                                                    <?= htmlspecialchars(strtoupper(substr($req['username'], 0, 1)), ENT_QUOTES, 'UTF-8') ?>
                                                <?php endif; ?>
                                            </a>
                                            <div>
                                                <div>
                                                    <span class="notif-tag tag-friend">FRIEND</span>
                                                    <a href="profile.php?u=<?= urlencode($req['username']) ?>"><strong><?= htmlspecialchars($req['username'], ENT_QUOTES, 'UTF-8') ?></strong></a><?= renderUserBadges($req['username'], $req['role'] ?? 'user') ?>
                                                    sent you a friend request.
                                                </div>
                                                <div class="notif-time"><?= htmlspecialchars(date('Y-m-d H:i', strtotime($req['created_at'])), ENT_QUOTES, 'UTF-8') ?></div>
                                            </div>
                                        </div>
                                        <div class="notif-actions">
                                            <form action="index.php#notifications-box" method="post" style="margin: 0;">
                                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                                                <input type="hidden" name="notif_action" value="accept_friend">
                                                <input type="hidden" name="requester_id" value="<?= (int) $req['id'] ?>">
                                                <button type="submit" class="btn btn-approve btn-xs">Accept</button>
                                            </form>
                                            <form action="index.php#notifications-box" method="post" style="margin: 0;">
                                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                                                <input type="hidden" name="notif_action" value="decline_friend">
                                                <input type="hidden" name="requester_id" value="<?= (int) $req['id'] ?>">
                                                <button type="submit" class="btn btn-xs">Decline</button>
                                            </form>
                                        </div>
                                    </div>
                                <?php endforeach; ?>

                                <!-- 2. Pending Profile Wall Comments -->
                                <?php if ($pendingWallCount > 0): ?>
                                    <div class="notif-item unread">
                                        <div class="notif-main">
                                            <div>
                                                <span class="notif-tag">WALL</span>
                                                You have <strong><?= $pendingWallCount ?></strong> profile wall comment(s) waiting for your approval.
                                            </div>
                                        </div>
                                        <div class="notif-actions">
                                            <a href="profile.php?u=<?= urlencode($_SESSION['username']) ?>" class="btn btn-primary btn-xs">Review &raquo;</a>
                                        </div>
                                    </div>
                                <?php endif; ?>

                                <!-- 3. Role Level-Up, Accepted Friend & System Notifications -->
                                <?php foreach ($userNotifications as $n): ?>
                                    <?php
                                    $isUnread = ((int) $n['is_read'] === 0);
                                    $isLevelUp = ($n['type'] === 'level_up');
                                    $itemClass = 'notif-item' . ($isLevelUp ? ' levelup' : ($isUnread ? ' unread' : ''));
                                    ?>
                                    <div class="<?= $itemClass ?>">
                                        <div class="notif-main">
                                            <div>
                                                <div>
                                                    <?php if ($isLevelUp): ?>
                                                        <span class="notif-tag tag-levelup">&#9733; LEVEL UP</span>
                                                    <?php elseif ($n['type'] === 'friend_accept'): ?>
                                                        <span class="notif-tag tag-friend">FRIEND</span>
                                                    <?php else: ?>
                                                        <span class="notif-tag">NOTICE</span>
                                                    <?php endif; ?>
                                                    <?= htmlspecialchars($n['message'], ENT_QUOTES, 'UTF-8') ?>
                                                    <?php if (!empty($n['link'])): ?>
                                                        <a href="<?= htmlspecialchars($n['link'], ENT_QUOTES, 'UTF-8') ?>" style="margin-left: 4px;"><strong>View &raquo;</strong></a>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="notif-time"><?= htmlspecialchars(date('Y-m-d H:i', strtotime($n['created_at'])), ENT_QUOTES, 'UTF-8') ?></div>
                                            </div>
                                        </div>
                                        <div class="notif-actions">
                                            <form action="index.php#notifications-box" method="post" style="margin: 0;">
                                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                                                <input type="hidden" name="notif_action" value="dismiss_notif">
                                                <input type="hidden" name="notif_id" value="<?= (int) $n['id'] ?>">
                                                <button type="submit" class="btn btn-xs" title="Dismiss">&times;</button>
                                            </form>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </section>
                <?php endif; ?>

                <!-- Welcome Box -->
                <section class="box">
                    <div class="box-title">
                        <span>Welcome to DuskNet</span>
                    </div>
                    <div class="box-body">
                        <?php if ($isLoggedIn): ?>
                            <p style="margin-top: 0; margin-bottom: 9px;">
                                Welcome back, <a href="profile.php?u=<?= urlencode($_SESSION['username']) ?>"><strong><?= htmlspecialchars($_SESSION['username'], ENT_QUOTES, 'UTF-8') ?></strong></a><?= renderUserBadges($_SESSION['username'], $_SESSION['role'] ?? 'user') ?>. Pick a public room below to start chatting.
                            </p>
                            <a href="rooms.php" class="btn btn-primary">Browse Rooms</a>
                            <a href="profile.php?u=<?= urlencode($_SESSION['username']) ?>" class="btn">My Profile</a>
                            <a href="account.php" class="btn">My Account</a>
                        <?php else: ?>
                            <p style="margin-top: 0; margin-bottom: 9px;">
                                Welcome to <strong>DuskNet</strong>. Pick a public room below to start chatting, or sign in with your account.
                            </p>
                            <a href="register.php" class="btn btn-primary">Create Account</a>
                            <a href="rooms.php" class="btn">Browse Rooms</a>
                        <?php endif; ?>
                    </div>
                </section>

                <!-- Public Chat Rooms with Quick Filter -->
                <section class="box">
                    <div class="box-title">
                        <span>Public Chat Rooms</span>
                        <input type="text" id="roomFilter" class="room-filter" placeholder="Filter rooms...">
                    </div>
                    <table class="rooms" id="roomsTable">
                        <thead>
                            <tr>
                                <th>Room</th>
                                <th>Topic</th>
                                <th>Users</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><strong>#lounge</strong></td>
                                <td>General daily chat</td>
                                <td>0</td>
                                <td><a href="room.php?id=lounge">Join &raquo;</a></td>
                            </tr>
                            <tr>
                                <td><strong>#music</strong></td>
                                <td>Songs, albums &amp; radio</td>
                                <td>0</td>
                                <td><a href="room.php?id=music">Join &raquo;</a></td>
                            </tr>
                            <tr>
                                <td><strong>#games</strong></td>
                                <td>PC, retro &amp; indie games</td>
                                <td>0</td>
                                <td><a href="room.php?id=games">Join &raquo;</a></td>
                            </tr>
                        </tbody>
                    </table>
                    <div id="noRoomsMsg" class="box-body empty-note" style="display: none;">
                        No matching rooms found.
                    </div>
                </section>

                <!-- Who's Online (Real DB users active in last 5 minutes) -->
                <section class="box">
                    <div class="box-title">
                        <span>Who's Online (<?= $onlineCount ?>)</span>
                    </div>
                    <div class="box-body">
                        <?php if ($onlineCount > 0): ?>
                            <div class="online-list">
                                <?php
                                $rendered = [];
                                foreach ($onlineUsers as $u) {
                                    $safeName = htmlspecialchars($u['username'], ENT_QUOTES, 'UTF-8');
                                    $badges = renderUserBadges($u['username'], $u['role']);
                                    $rendered[] = '<a href="profile.php?u=' . urlencode($u['username']) . '"><strong>' . $safeName . '</strong></a>' . $badges;
                                }
                                echo implode(', ', $rendered);
                                ?>
                            </div>
                        <?php else: ?>
                            <span class="empty-note">No users online right now.</span>
                        <?php endif; ?>
                    </div>
                </section>

                <!-- New Members Showcase (with Avatars) -->
                <section class="box">
                    <div class="box-title">
                        <span>New Members</span>
                        <a href="members.php" style="color: #ffffff; font-weight: normal; font-size: 10px;">[All Members]</a>
                    </div>
                    <div class="box-body">
                        <?php if (!empty($newMembers)): ?>
                            <div class="new-members-grid">
                                <?php foreach ($newMembers as $m): ?>
                                    <?php $mAvatar = getSafeAvatar($m['avatar'] ?? ''); ?>
                                    <a href="profile.php?u=<?= urlencode($m['username']) ?>" class="member-card" title="View <?= htmlspecialchars($m['username'], ENT_QUOTES, 'UTF-8') ?>'s profile">
                                        <div class="member-avatar">
                                            <?php if ($mAvatar !== ''): ?>
                                                <img src="<?= htmlspecialchars($mAvatar, ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($m['username'], ENT_QUOTES, 'UTF-8') ?>">
                                            <?php else: ?>
                                                <?= htmlspecialchars(strtoupper(substr($m['username'], 0, 1)), ENT_QUOTES, 'UTF-8') ?>
                                            <?php endif; ?>
                                        </div>
                                        <div class="member-name"><?= htmlspecialchars($m['username'], ENT_QUOTES, 'UTF-8') ?></div>
                                        <?php $mBadges = renderUserBadges($m['username'], $m['role'] ?? 'user'); ?>
                                        <?php if ($mBadges !== ''): ?>
                                            <div class="member-badges"><?= $mBadges ?></div>
                                        <?php endif; ?>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <span class="empty-note">No registered members yet.</span>
                        <?php endif; ?>
                    </div>
                </section>

            </div>

            <!-- Right Column (Sidebar) -->
            <aside class="side-col">

                <?php if ($isLoggedIn): ?>
                    <!-- Logged-in Account Panel -->
                    <section class="box">
                        <div class="box-title">My Account</div>
                        <div class="box-body">
                            <div class="my-profile-head">
                                <a href="profile.php?u=<?= urlencode($_SESSION['username']) ?>" class="my-avatar-link" title="View My Profile">
                                    <?php if ($myAvatar !== ''): ?>
                                        <img src="<?= htmlspecialchars($myAvatar, ENT_QUOTES, 'UTF-8') ?>" alt="My Avatar">
                                    <?php else: ?>
                                        <?= htmlspecialchars(strtoupper(substr($_SESSION['username'], 0, 1)), ENT_QUOTES, 'UTF-8') ?>
                                    <?php endif; ?>
                                </a>
                                <div class="my-profile-meta">
                                    <div>
                                        <a href="profile.php?u=<?= urlencode($_SESSION['username']) ?>"><strong><?= htmlspecialchars($_SESSION['username'], ENT_QUOTES, 'UTF-8') ?></strong></a><?= renderUserBadges($_SESSION['username'], $_SESSION['role'] ?? 'user') ?>
                                    </div>
                                    <div style="margin-top: 2px; font-size: 10px;">
                                        Role:
                                        <?php $myRole = strtolower(trim($_SESSION['role'] ?? 'user')); ?>
                                        <?php if ($myRole === 'admin'): ?>
                                            <strong class="role-admin">Admin</strong>
                                        <?php else: ?>
                                            <strong><?= htmlspecialchars($myRole === 'vip' ? 'VIP' : ucfirst($myRole), ENT_QUOTES, 'UTF-8') ?></strong>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <div class="account-actions">
                                <a href="profile.php?u=<?= urlencode($_SESSION['username']) ?>" class="btn btn-primary">My Profile</a>
                                <a href="account.php" class="btn">Settings</a>
                                <a href="logout.php" class="btn">Logout</a>
                            </div>
                        </div>
                    </section>
                <?php else: ?>
                    <!-- Member Login Form -->
                    <section class="box">
                        <div class="box-title">Member Login</div>
                        <div class="box-body">
                            <form action="login.php" method="post">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                                <div class="form-group">
                                    <label for="username">Username</label>
                                    <input type="text" id="username" name="username" required>
                                </div>
                                <div class="form-group">
                                    <label for="password">Password</label>
                                    <input type="password" id="password" name="password" required>
                                </div>
                                <div class="form-group" style="font-size: 11px;">
                                    <label style="font-weight: normal;">
                                        <input type="checkbox" name="remember"> Remember me
                                    </label>
                                </div>
                                <button type="submit" class="btn btn-primary">Login</button>
                                <div style="margin-top: 8px; font-size: 10px;">
                                    <a href="forgot.php">Forgot password?</a>
                                </div>
                            </form>
                        </div>
                    </section>

                    <!-- Sign Up Box -->
                    <section class="box">
                        <div class="box-title">New Member</div>
                        <div class="box-body">
                            <p style="margin-top: 0; margin-bottom: 8px; font-size: 11px;">
                                Not registered yet? Create an account to reserve your nickname.
                            </p>
                            <a href="register.php" class="btn">Sign Up</a>
                        </div>
                    </section>
                <?php endif; ?>

                <!-- Statistics (Real DB counts) -->
                <section class="box">
                    <div class="box-title">Statistics</div>
                    <div class="box-body">
                        <ul class="stats-list">
                            <li><span>Online Now</span><strong><?= $onlineCount ?></strong></li>
                            <li><span>Active Rooms</span><strong>3</strong></li>
                            <li><span>Total Members</span><strong><?= $totalMembers ?></strong></li>
                        </ul>
                    </div>
                </section>

            </aside>

        </main>

        <!-- Footer -->
        <footer class="footer">
            &copy; DuskNet &bull;
            <a href="about.php">About</a> &bull;
            <a href="rules.php">Rules</a> &bull;
            <a href="privacy.php">Privacy</a> &bull;
            <a href="contact.php">Contact</a>
        </footer>

    </div>

    <script>
        /* Theme Switcher with localStorage persistence */
        const themeButtons = document.querySelectorAll('[data-set-theme]');
        function applyTheme(theme) {
            document.documentElement.setAttribute('data-theme', theme);
            themeButtons.forEach(btn => {
                btn.classList.toggle('active', btn.dataset.setTheme === theme);
            });
            try { localStorage.setItem('dusknet_theme', theme); } catch (e) {}
        }

        themeButtons.forEach(btn => {
            btn.addEventListener('click', () => applyTheme(btn.dataset.setTheme));
        });

        try {
            const savedTheme = localStorage.getItem('dusknet_theme');
            if (savedTheme) applyTheme(savedTheme);
        } catch (e) {}

        /* Time Spent on Site Counter (starts from 1s) */
        let secondsOnSite = 1;
        function updateSessionTimer() {
            const hrs = String(Math.floor(secondsOnSite / 3600)).padStart(2, '0');
            const mins = String(Math.floor((secondsOnSite % 3600) / 60)).padStart(2, '0');
            const secs = String(secondsOnSite % 60).padStart(2, '0');
            document.getElementById('sessionTimer').textContent = `${hrs}:${mins}:${secs}`;
            secondsOnSite++;
        }
        updateSessionTimer();
        setInterval(updateSessionTimer, 1000);

        /* Dismissible Notice Bar */
        document.getElementById('closeNotice').addEventListener('click', () => {
            document.getElementById('noticeBar').style.display = 'none';
        });

        /* Quick Room Filter */
        const roomFilter = document.getElementById('roomFilter');
        const roomRows = document.querySelectorAll('#roomsTable tbody tr');
        const noRoomsMsg = document.getElementById('noRoomsMsg');

        roomFilter.addEventListener('input', () => {
            const query = roomFilter.value.toLowerCase().trim();
            let visibleCount = 0;

            roomRows.forEach(row => {
                const match = row.textContent.toLowerCase().includes(query);
                row.style.display = match ? '' : 'none';
                if (match) visibleCount++;
            });

            noRoomsMsg.style.display = visibleCount === 0 ? 'block' : 'none';
        });
    </script>

</body>
</html>