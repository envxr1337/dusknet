<?php
session_start();
require_once __DIR__ . '/db.php';

// Generate CSRF token for secure form submissions
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$isLoggedIn = !empty($_SESSION['user_id']);
$myId       = $isLoggedIn ? (int) $_SESSION['user_id'] : 0;

/**
 * Renders role badges (Admin blue tick, Mod/VIP pill) and Official Account tag for "dusknet".
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

/**
 * Checks whether a user's last_seen timestamp is within the last 5 minutes.
 */
function isUserOnline(?string $lastSeen): bool
{
    if (empty($lastSeen)) {
        return false;
    }
    return (time() - strtotime($lastSeen)) <= 300;
}

$activeTab = $_GET['tab'] ?? 'all';
$allowedTabs = ['all', 'friends', 'requests', 'blocked'];
if (!in_array($activeTab, $allowedTabs, true)) {
    $activeTab = 'all';
}

// Guests can only view the All Members directory
if (!$isLoggedIn && $activeTab !== 'all') {
    $activeTab = 'all';
}

$searchQuery = trim($_GET['q'] ?? '');
$filterMode  = $_GET['filter'] ?? 'all';
if (!in_array($filterMode, ['all', 'online', 'staff'], true)) {
    $filterMode = 'all';
}

$feedbackOk  = '';
$feedbackErr = '';

$allMembers       = [];
$myFriends        = [];
$incomingRequests = [];
$outgoingRequests = [];
$blockedUsers     = [];

$totalMembersCount = 0;
$onlineTotalCount  = 0;
$friendsCount      = 0;
$incomingCount     = 0;
$blockedCount      = 0;

// Maps keyed by other user's ID for quick button state lookup
$relationMap = []; // user_id => 'friends' | 'sent' | 'received'
$blockedByMe = []; // user_id => true
$blockedMe   = []; // user_id => true

try {
    // Purge expired 24-hour temporary accounts
    try {
        $pdo->exec('DELETE FROM users WHERE expires_at IS NOT NULL AND expires_at <= NOW()');
    } catch (Throwable $e) {
        // Ignore if expires_at column doesn't exist yet
    }

    // Ensure required tables exist (friendships, notifications, user_blocks, user_reports)
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

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS user_blocks (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            blocker_id INT UNSIGNED NOT NULL,
            blocked_id INT UNSIGNED NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_block_pair (blocker_id, blocked_id),
            INDEX idx_blocked (blocked_id),
            CONSTRAINT fk_block_blocker FOREIGN KEY (blocker_id) REFERENCES users(id) ON DELETE CASCADE,
            CONSTRAINT fk_block_blocked FOREIGN KEY (blocked_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS user_reports (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            reporter_id INT UNSIGNED NOT NULL,
            reported_user_id INT UNSIGNED NOT NULL,
            reason VARCHAR(60) NOT NULL,
            details VARCHAR(300) NULL DEFAULT NULL,
            status ENUM('open', 'reviewed', 'dismissed') NOT NULL DEFAULT 'open',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_report_status (status, created_at),
            CONSTRAINT fk_report_reporter FOREIGN KEY (reporter_id) REFERENCES users(id) ON DELETE CASCADE,
            CONSTRAINT fk_report_target FOREIGN KEY (reported_user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    // Check optional columns on users table so queries never break on older schemas
    $userCols = [];
    foreach ($pdo->query('SHOW COLUMNS FROM users')->fetchAll() as $col) {
        $userCols[$col['Field']] = true;
    }
    $selAvatar = isset($userCols['avatar']) ? 'u.avatar' : "NULL AS avatar";
    $selStatus = isset($userCols['status_text']) ? 'u.status_text' : "NULL AS status_text";
    $selSeen   = isset($userCols['last_seen']) ? 'u.last_seen' : "NULL AS last_seen";

    // Keep logged-in user's activity & session synced
    if ($isLoggedIn) {
        if (isset($userCols['last_seen'])) {
            $pdo->prepare('UPDATE users SET last_seen = NOW() WHERE id = ?')->execute([$myId]);
        }
        $meStmt = $pdo->prepare('SELECT username, role FROM users WHERE id = ? LIMIT 1');
        $meStmt->execute([$myId]);
        $me = $meStmt->fetch();
        if ($me) {
            $_SESSION['username'] = $me['username'];
            $_SESSION['role']     = $me['role'];
        }
    }

    // =========================================================================
    // Handle POST Actions (Add/Remove Friend, Accept/Decline, Block/Unblock, Report)
    // =========================================================================
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['member_action'])) {
        $token    = $_POST['csrf_token'] ?? '';
        $action   = $_POST['member_action'];
        $targetId = (int) ($_POST['target_id'] ?? 0);
        $postedTab = $_POST['return_tab'] ?? $activeTab;
        if (in_array($postedTab, $allowedTabs, true)) {
            $activeTab = $postedTab;
        }

        if (!$isLoggedIn) {
            $feedbackErr = 'You must be logged in to perform this action.';
        } elseif (!hash_equals($_SESSION['csrf_token'], $token)) {
            $feedbackErr = 'Security check failed (invalid token). Please refresh and try again.';
        } elseif ($targetId <= 0 || $targetId === $myId) {
            $feedbackErr = 'Invalid target user.';
        } else {
            // Verify target user exists
            $tStmt = $pdo->prepare('SELECT id, username FROM users WHERE id = ? LIMIT 1');
            $tStmt->execute([$targetId]);
            $targetUser = $tStmt->fetch();

            if (!$targetUser) {
                $feedbackErr = 'User not found.';
            } elseif ($action === 'add_friend') {
                // Prevent friend request if either user blocked the other
                $blkCheck = $pdo->prepare(
                    'SELECT id FROM user_blocks WHERE (blocker_id = ? AND blocked_id = ?) OR (blocker_id = ? AND blocked_id = ?) LIMIT 1'
                );
                $blkCheck->execute([$myId, $targetId, $targetId, $myId]);

                if ($blkCheck->fetch()) {
                    $feedbackErr = 'Cannot send a friend request because one of you has blocked the other.';
                } else {
                    $relCheck = $pdo->prepare(
                        'SELECT id, requester_id, status FROM friendships
                         WHERE (requester_id = ? AND addressee_id = ?) OR (requester_id = ? AND addressee_id = ?) LIMIT 1'
                    );
                    $relCheck->execute([$myId, $targetId, $targetId, $myId]);
                    $rel = $relCheck->fetch();

                    if ($rel) {
                        if ($rel['status'] === 'accepted') {
                            $feedbackOk = 'You are already friends with ' . $targetUser['username'] . '.';
                        } elseif ((int) $rel['requester_id'] === $targetId) {
                            $pdo->prepare("UPDATE friendships SET status = 'accepted' WHERE id = ?")->execute([$rel['id']]);
                            $pdo->prepare('INSERT INTO notifications (user_id, type, message, link) VALUES (?, ?, ?, ?)')
                                ->execute([
                                    $targetId,
                                    'friend_accept',
                                    $_SESSION['username'] . ' accepted your friend request!',
                                    'profile.php?u=' . urlencode($_SESSION['username']),
                                ]);
                            $feedbackOk = 'Friend request from ' . $targetUser['username'] . ' accepted!';
                        } else {
                            $feedbackOk = 'Friend request already sent to ' . $targetUser['username'] . '.';
                        }
                    } else {
                        $pdo->prepare("INSERT INTO friendships (requester_id, addressee_id, status) VALUES (?, ?, 'pending')")
                            ->execute([$myId, $targetId]);
                        $feedbackOk = 'Friend request sent to ' . $targetUser['username'] . '.';
                    }
                }
            } elseif ($action === 'cancel_request') {
                $pdo->prepare("DELETE FROM friendships WHERE requester_id = ? AND addressee_id = ? AND status = 'pending'")
                    ->execute([$myId, $targetId]);
                $feedbackOk = 'Friend request to ' . $targetUser['username'] . ' cancelled.';
            } elseif ($action === 'accept_friend') {
                $upd = $pdo->prepare(
                    "UPDATE friendships SET status = 'accepted' WHERE requester_id = ? AND addressee_id = ? AND status = 'pending'"
                );
                $upd->execute([$targetId, $myId]);
                if ($upd->rowCount() > 0) {
                    $pdo->prepare('INSERT INTO notifications (user_id, type, message, link) VALUES (?, ?, ?, ?)')
                        ->execute([
                            $targetId,
                            'friend_accept',
                            $_SESSION['username'] . ' accepted your friend request!',
                            'profile.php?u=' . urlencode($_SESSION['username']),
                        ]);
                    $feedbackOk = 'You are now friends with ' . $targetUser['username'] . '!';
                }
            } elseif ($action === 'decline_friend') {
                $pdo->prepare("DELETE FROM friendships WHERE requester_id = ? AND addressee_id = ? AND status = 'pending'")
                    ->execute([$targetId, $myId]);
                $feedbackOk = 'Declined friend request from ' . $targetUser['username'] . '.';
            } elseif ($action === 'remove_friend') {
                $pdo->prepare(
                    "DELETE FROM friendships WHERE status = 'accepted' AND ((requester_id = ? AND addressee_id = ?) OR (requester_id = ? AND addressee_id = ?))"
                )->execute([$myId, $targetId, $targetId, $myId]);
                $feedbackOk = $targetUser['username'] . ' has been removed from your friends list.';
            } elseif ($action === 'block_user') {
                $pdo->prepare('INSERT IGNORE INTO user_blocks (blocker_id, blocked_id) VALUES (?, ?)')
                    ->execute([$myId, $targetId]);
                // Blocking automatically removes any friendship or pending request
                $pdo->prepare(
                    'DELETE FROM friendships WHERE (requester_id = ? AND addressee_id = ?) OR (requester_id = ? AND addressee_id = ?)'
                )->execute([$myId, $targetId, $targetId, $myId]);
                $feedbackOk = $targetUser['username'] . ' has been blocked and removed from your friends.';
            } elseif ($action === 'unblock_user') {
                $pdo->prepare('DELETE FROM user_blocks WHERE blocker_id = ? AND blocked_id = ?')
                    ->execute([$myId, $targetId]);
                $feedbackOk = $targetUser['username'] . ' has been unblocked.';
            } elseif ($action === 'report_user') {
                $allowedReasons = [
                    'Harassment / Bullying',
                    'Spam / Scam',
                    'Offensive Profile or Avatar',
                    'Impersonation',
                    'Rule Violation / Other',
                ];
                $reason  = trim($_POST['report_reason'] ?? '');
                $details = trim($_POST['report_details'] ?? '');

                if (!in_array($reason, $allowedReasons, true)) {
                    $feedbackErr = 'Please select a valid report reason.';
                } elseif (mb_strlen($details) > 300) {
                    $feedbackErr = 'Report details must be 300 characters or fewer.';
                } else {
                    // Prevent duplicate reports for the same user within 24 hours
                    $dupRep = $pdo->prepare(
                        'SELECT id FROM user_reports
                         WHERE reporter_id = ? AND reported_user_id = ? AND created_at >= (NOW() - INTERVAL 24 HOUR)
                         LIMIT 1'
                    );
                    $dupRep->execute([$myId, $targetId]);

                    if ($dupRep->fetch()) {
                        $feedbackErr = 'You have already reported ' . $targetUser['username'] . ' within the last 24 hours.';
                    } else {
                        $pdo->prepare(
                            'INSERT INTO user_reports (reporter_id, reported_user_id, reason, details) VALUES (?, ?, ?, ?)'
                        )->execute([$myId, $targetId, $reason, $details !== '' ? $details : null]);
                        $feedbackOk = 'Report submitted for ' . $targetUser['username'] . '. DuskNet staff will review it.';
                    }
                }
            }
        }
    }

    // =========================================================================
    // Load User Relationships & Blocks for Logged-in User
    // =========================================================================
    if ($isLoggedIn) {
        $relStmt = $pdo->prepare(
            'SELECT requester_id, addressee_id, status FROM friendships WHERE requester_id = ? OR addressee_id = ?'
        );
        $relStmt->execute([$myId, $myId]);
        foreach ($relStmt->fetchAll() as $r) {
            $otherId = ((int) $r['requester_id'] === $myId) ? (int) $r['addressee_id'] : (int) $r['requester_id'];
            if ($r['status'] === 'accepted') {
                $relationMap[$otherId] = 'friends';
            } elseif ((int) $r['requester_id'] === $myId) {
                $relationMap[$otherId] = 'sent';
            } else {
                $relationMap[$otherId] = 'received';
            }
        }

        $blkStmt = $pdo->prepare('SELECT blocker_id, blocked_id FROM user_blocks WHERE blocker_id = ? OR blocked_id = ?');
        $blkStmt->execute([$myId, $myId]);
        foreach ($blkStmt->fetchAll() as $b) {
            if ((int) $b['blocker_id'] === $myId) {
                $blockedByMe[(int) $b['blocked_id']] = true;
            } else {
                $blockedMe[(int) $b['blocker_id']] = true;
            }
        }

        // Load Friends List
        $frStmt = $pdo->prepare(
            "SELECT u.id, u.username, u.role, {$selAvatar}, {$selStatus}, {$selSeen}, u.created_at
             FROM friendships f
             JOIN users u ON u.id = IF(f.requester_id = ?, f.addressee_id, f.requester_id)
             WHERE f.status = 'accepted' AND (f.requester_id = ? OR f.addressee_id = ?)
             ORDER BY u.last_seen DESC, u.username ASC"
        );
        $frStmt->execute([$myId, $myId, $myId]);
        $myFriends    = $frStmt->fetchAll();
        $friendsCount = count($myFriends);

        // Load Incoming Friend Requests
        $inStmt = $pdo->prepare(
            "SELECT u.id, u.username, u.role, {$selAvatar}, {$selSeen}, f.created_at AS requested_at
             FROM friendships f
             JOIN users u ON u.id = f.requester_id
             WHERE f.addressee_id = ? AND f.status = 'pending'
             ORDER BY f.created_at DESC"
        );
        $inStmt->execute([$myId]);
        $incomingRequests = $inStmt->fetchAll();
        $incomingCount    = count($incomingRequests);

        // Load Outgoing Friend Requests
        $outStmt = $pdo->prepare(
            "SELECT u.id, u.username, u.role, {$selAvatar}, {$selSeen}, f.created_at AS requested_at
             FROM friendships f
             JOIN users u ON u.id = f.addressee_id
             WHERE f.requester_id = ? AND f.status = 'pending'
             ORDER BY f.created_at DESC"
        );
        $outStmt->execute([$myId]);
        $outgoingRequests = $outStmt->fetchAll();

        // Load Blocked Users
        $buStmt = $pdo->prepare(
            "SELECT u.id, u.username, u.role, {$selAvatar}, b.created_at AS blocked_at
             FROM user_blocks b
             JOIN users u ON u.id = b.blocked_id
             WHERE b.blocker_id = ?
             ORDER BY b.created_at DESC"
        );
        $buStmt->execute([$myId]);
        $blockedUsers = $buStmt->fetchAll();
        $blockedCount = count($blockedUsers);
    }

    // =========================================================================
    // Load All Members Directory (with search & filter)
    // =========================================================================
    $totalMembersCount = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    if (isset($userCols['last_seen'])) {
        $onlineTotalCount = (int) $pdo->query('SELECT COUNT(*) FROM users WHERE last_seen >= (NOW() - INTERVAL 5 MINUTE)')->fetchColumn();
    }

    $where  = [];
    $params = [];

    if ($searchQuery !== '') {
        $where[]  = 'u.username LIKE ?';
        $params[] = '%' . $searchQuery . '%';
    }
    if ($filterMode === 'online' && isset($userCols['last_seen'])) {
        $where[] = 'u.last_seen >= (NOW() - INTERVAL 5 MINUTE)';
    } elseif ($filterMode === 'staff') {
        $where[] = "u.role IN ('admin', 'mod', 'vip')";
    }

    $whereSql = !empty($where) ? ('WHERE ' . implode(' AND ', $where)) : '';
    $orderSql = isset($userCols['last_seen'])
        ? 'ORDER BY (u.last_seen >= (NOW() - INTERVAL 5 MINUTE)) DESC, u.created_at DESC'
        : 'ORDER BY u.created_at DESC';

    $allStmt = $pdo->prepare(
        "SELECT u.id, u.username, u.role, {$selAvatar}, {$selStatus}, {$selSeen}, u.created_at
         FROM users u
         {$whereSql}
         {$orderSql}
         LIMIT 100"
    );
    $allStmt->execute($params);
    $allMembers = $allStmt->fetchAll();
} catch (Throwable $e) {
    $feedbackErr = 'Unable to load members right now. Please try again later.';
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="blue">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Members &amp; Friends - DuskNet</title>
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
            --err-bg: #fdecec;
            --err-border: #cc7a7a;
            --err-text: #8a1f1f;
            --ok-bg: #ebf7eb;
            --ok-border: #7cb37c;
            --ok-text: #1f5c1f;
            --role-admin: #c42323;
        }

        /* Theme: Dusk */
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
            --err-bg: #fae8e6;
            --err-border: #c98179;
            --err-text: #7d2118;
            --ok-bg: #edf5ea;
            --ok-border: #84a879;
            --ok-text: #27521d;
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
            --err-bg: #362022;
            --err-border: #7a3e44;
            --err-text: #f0a8ae;
            --ok-bg: #1f3322;
            --ok-border: #3f6e46;
            --ok-text: #9ee0a8;
            --role-admin: #ff6b6b;
        }

        * { box-sizing: border-box; }

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

        a { color: var(--link); text-decoration: none; }
        a:hover { text-decoration: underline; }

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

        .brand { font-size: 18px; font-weight: bold; color: #ffffff; }
        .tagline { font-size: 10px; color: #d6e0ec; margin-top: 1px; }

        .header-search { display: flex; align-items: center; gap: 4px; }
        .header-search input[type="text"] {
            width: 175px;
            padding: 3px 6px;
            border: 1px solid var(--header-border);
            background-color: var(--input-bg);
            color: var(--text);
            font-family: inherit;
            font-size: 11px;
        }
        .header-search input:focus { outline: none; }
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

        .header-right { text-align: right; font-size: 11px; }
        .header-right a { color: #ffffff; }
        .sep { color: #9bb1c9; margin: 0 4px; }
        .clock-bar { font-size: 10px; color: #d6e0ec; margin-top: 3px; }

        /* Navigation & Theme Switcher */
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

        .nav-links { display: flex; flex-wrap: wrap; align-items: center; gap: 6px; }
        .nav-links a { color: var(--link); font-weight: bold; }
        .nav-sep { color: var(--border-strong); }

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

        /* Content */
        .content {
            padding: 12px;
        }

        /* Tabs Bar */
        .page-tabs {
            display: flex;
            flex-wrap: wrap;
            gap: 5px;
            margin-bottom: 10px;
            border-bottom: 1px solid var(--border-strong);
            padding-bottom: 7px;
        }

        .page-tab {
            background: linear-gradient(to bottom, var(--btn-top), var(--btn-bot));
            color: var(--btn-text);
            border: 1px solid var(--border-strong);
            padding: 4px 10px;
            font-size: 11px;
            font-weight: bold;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }

        .page-tab:hover { text-decoration: none; filter: brightness(0.96); }

        .page-tab.active {
            background: linear-gradient(to bottom, var(--btn-primary-top), var(--btn-primary-bot));
            color: #ffffff;
            border-color: var(--btn-primary-border);
        }

        .tab-pill {
            display: inline-block;
            padding: 0 4px;
            font-size: 9px;
            border: 1px solid rgba(0, 0, 0, 0.25);
            background-color: rgba(255, 255, 255, 0.25);
        }

        .tab-pill.alert {
            background-color: #c42323;
            color: #ffffff;
            border-color: #7d1111;
        }

        /* Classic Boxes */
        .box {
            border: 1px solid var(--border-strong);
            background-color: var(--bg-box);
            margin-bottom: 12px;
        }

        .box:last-child { margin-bottom: 0; }

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
            flex-wrap: wrap;
            gap: 6px;
        }

        .box-body { padding: 10px 11px; }

        /* Status Messages */
        .msg-box {
            padding: 7px 9px;
            margin-bottom: 10px;
            font-size: 11px;
            border: 1px solid;
        }
        .msg-error { background-color: var(--err-bg); border-color: var(--err-border); color: var(--err-text); }
        .msg-success { background-color: var(--ok-bg); border-color: var(--ok-border); color: var(--ok-text); }

        /* Filter Toolbar */
        .filter-bar {
            display: flex;
            flex-wrap: wrap;
            justify-content: space-between;
            align-items: center;
            gap: 8px;
            padding: 7px 10px;
            background-color: var(--bg-soft);
            border-bottom: 1px solid var(--border);
        }

        .filter-bar form {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 5px;
            margin: 0;
        }

        .filter-bar input[type="text"],
        .filter-bar select {
            padding: 3px 6px;
            border: 1px solid var(--input-border);
            background-color: var(--input-bg);
            color: var(--text);
            font-family: inherit;
            font-size: 11px;
        }

        /* Member / Friend Row Cards */
        .member-list {
            display: flex;
            flex-direction: column;
        }

        .member-row {
            display: flex;
            flex-wrap: wrap;
            justify-content: space-between;
            align-items: center;
            gap: 10px;
            padding: 8px 10px;
            border-bottom: 1px dotted var(--border);
        }

        .member-row:last-child { border-bottom: none; }
        .member-row:hover { background-color: var(--bg-hover); }

        .member-left {
            display: flex;
            align-items: center;
            gap: 10px;
            flex: 1 1 260px;
            min-width: 0;
        }

        .m-avatar {
            width: 48px;
            height: 48px;
            border: 1px solid var(--border-strong);
            background-color: var(--bg-soft);
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            flex-shrink: 0;
            font-size: 16px;
            font-weight: bold;
            color: var(--text-muted);
            text-decoration: none;
        }

        .m-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }

        .m-info { min-width: 0; flex: 1; }

        .m-name-line {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 3px;
            font-size: 12px;
        }

        .m-meta {
            font-size: 10px;
            color: var(--text-muted);
            margin-top: 2px;
        }

        .m-status {
            font-size: 10px;
            font-style: italic;
            color: var(--text-muted);
            margin-top: 2px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            max-width: 340px;
        }

        .online-dot {
            display: inline-block;
            width: 7px;
            height: 7px;
            border-radius: 50%;
            margin-right: 3px;
            vertical-align: middle;
        }
        .online-dot.on  { background-color: #27a844; border: 1px solid #166e2a; }
        .online-dot.off { background-color: #9aa3ad; border: 1px solid #6b737d; }

        .member-actions {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 4px;
            flex-shrink: 0;
        }

        /* Badges */
        .role-pill {
            display: inline-block;
            margin-left: 2px;
            padding: 0 4px;
            font-size: 9px;
            font-weight: bold;
            border: 1px solid;
            vertical-align: 1px;
        }
        .role-mod { background-color: #e6f4ea; color: #1b6e2f; border-color: #6ab07c; }
        .role-vip { background-color: #fff4d6; color: #946200; border-color: #d4a638; }

        .admin-tick {
            position: relative;
            display: inline-flex;
            align-items: center;
            vertical-align: -2px;
            margin-left: 2px;
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
            margin-left: 2px;
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

        .you-tag {
            display: inline-block;
            padding: 1px 5px;
            font-size: 9px;
            font-weight: bold;
            background-color: var(--bg-soft);
            border: 1px solid var(--border-strong);
            color: var(--text-muted);
        }

        /* Buttons */
        .btn {
            display: inline-block;
            background: linear-gradient(to bottom, var(--btn-top), var(--btn-bot));
            color: var(--btn-text);
            border: 1px solid var(--border-strong);
            box-shadow: inset 1px 1px 0 rgba(255, 255, 255, 0.5);
            padding: 3px 9px;
            font-family: inherit;
            font-size: 11px;
            font-weight: bold;
            cursor: pointer;
            text-decoration: none;
        }
        .btn:hover { text-decoration: none; filter: brightness(0.96); }

        .btn-xs { padding: 2px 7px; font-size: 10px; }

        .btn-primary {
            background: linear-gradient(to bottom, var(--btn-primary-top), var(--btn-primary-bot));
            color: #ffffff;
            border-color: var(--btn-primary-border);
            box-shadow: inset 1px 1px 0 rgba(255, 255, 255, 0.25);
        }

        .btn-approve {
            background: linear-gradient(to bottom, #489957, #2e6e3a);
            color: #ffffff;
            border-color: #1f4f28;
            box-shadow: inset 1px 1px 0 rgba(255, 255, 255, 0.25);
        }

        .btn-danger {
            background: linear-gradient(to bottom, #c94b4b, #992d2d);
            color: #ffffff;
            border-color: #701d1d;
            box-shadow: inset 1px 1px 0 rgba(255, 255, 255, 0.2);
        }

        .btn-warn {
            background: linear-gradient(to bottom, #d99b38, #ad751c);
            color: #ffffff;
            border-color: #7d520e;
            box-shadow: inset 1px 1px 0 rgba(255, 255, 255, 0.2);
        }

        .empty-note { color: var(--text-muted); font-style: italic; }

        /* Classic Modal Overlay (Report & Confirm dialogs) */
        .modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background-color: rgba(0, 0, 0, 0.55);
            z-index: 100;
            align-items: center;
            justify-content: center;
            padding: 12px;
        }

        .modal-overlay.open { display: flex; }

        .modal-box {
            width: 100%;
            max-width: 380px;
            background-color: var(--bg-box);
            border: 1px solid var(--border-strong);
            box-shadow: 3px 3px 0 rgba(0, 0, 0, 0.28);
        }

        .modal-body { padding: 10px 12px; }

        .modal-body label {
            display: block;
            font-weight: bold;
            margin-bottom: 3px;
        }

        .modal-body select,
        .modal-body textarea {
            width: 100%;
            padding: 4px 6px;
            border: 1px solid var(--input-border);
            background-color: var(--input-bg);
            color: var(--text);
            font-family: inherit;
            font-size: 11px;
            margin-bottom: 8px;
        }

        .modal-body textarea { resize: vertical; min-height: 64px; }

        .modal-actions {
            display: flex;
            justify-content: flex-end;
            gap: 6px;
            margin-top: 8px;
            padding-top: 8px;
            border-top: 1px dotted var(--border);
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
            <form action="members.php" method="get" class="header-search">
                <input type="hidden" name="tab" value="all">
                <input
                    type="text"
                    name="q"
                    value="<?= htmlspecialchars($searchQuery, ENT_QUOTES, 'UTF-8') ?>"
                    placeholder="Find a member..."
                    aria-label="Find a member"
                >
                <button type="submit">Search</button>
            </form>
            <div class="header-right">
                <div>
                    <?php if ($isLoggedIn): ?>
                        <strong><?= htmlspecialchars($_SESSION['username'], ENT_QUOTES, 'UTF-8') ?></strong><?= renderUserBadges($_SESSION['username'], $_SESSION['role'] ?? 'user') ?><span class="sep">|</span><a href="account.php">My Account</a><span class="sep">|</span><a href="logout.php">Logout</a>
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

        <!-- Main Content -->
        <main class="content">

            <!-- Section Tabs -->
            <div class="page-tabs">
                <a href="members.php?tab=all" class="page-tab <?= $activeTab === 'all' ? 'active' : '' ?>">
                    All Members <span class="tab-pill"><?= $totalMembersCount ?></span>
                </a>
                <?php if ($isLoggedIn): ?>
                    <a href="members.php?tab=friends" class="page-tab <?= $activeTab === 'friends' ? 'active' : '' ?>">
                        My Friends <span class="tab-pill"><?= $friendsCount ?></span>
                    </a>
                    <a href="members.php?tab=requests" class="page-tab <?= $activeTab === 'requests' ? 'active' : '' ?>">
                        Friend Requests
                        <?php if ($incomingCount > 0): ?>
                            <span class="tab-pill alert"><?= $incomingCount ?></span>
                        <?php else: ?>
                            <span class="tab-pill">0</span>
                        <?php endif; ?>
                    </a>
                    <a href="members.php?tab=blocked" class="page-tab <?= $activeTab === 'blocked' ? 'active' : '' ?>">
                        Blocked <span class="tab-pill"><?= $blockedCount ?></span>
                    </a>
                <?php endif; ?>
            </div>

            <?php if ($feedbackErr !== ''): ?>
                <div class="msg-box msg-error">
                    <?= htmlspecialchars($feedbackErr, ENT_QUOTES, 'UTF-8') ?>
                </div>
            <?php endif; ?>

            <?php if ($feedbackOk !== ''): ?>
                <div class="msg-box msg-success">
                    <?= htmlspecialchars($feedbackOk, ENT_QUOTES, 'UTF-8') ?>
                </div>
            <?php endif; ?>

            <?php if ($activeTab === 'all'): ?>
                <!-- ===========================================================
                     TAB 1: ALL MEMBERS DIRECTORY
                     =========================================================== -->
                <section class="box">
                    <div class="box-title">
                        <span>DuskNet Members Directory (<?= $totalMembersCount ?> total &bull; <?= $onlineTotalCount ?> online)</span>
                    </div>

                    <div class="filter-bar">
                        <form action="members.php" method="get">
                            <input type="hidden" name="tab" value="all">
                            <input type="text" name="q" value="<?= htmlspecialchars($searchQuery, ENT_QUOTES, 'UTF-8') ?>" placeholder="Filter by username...">
                            <select name="filter">
                                <option value="all" <?= $filterMode === 'all' ? 'selected' : '' ?>>All Users</option>
                                <option value="online" <?= $filterMode === 'online' ? 'selected' : '' ?>>Online Only</option>
                                <option value="staff" <?= $filterMode === 'staff' ? 'selected' : '' ?>>Admins / Mods / VIP</option>
                            </select>
                            <button type="submit" class="btn btn-primary btn-xs">Apply</button>
                            <?php if ($searchQuery !== '' || $filterMode !== 'all'): ?>
                                <a href="members.php?tab=all" class="btn btn-xs">Reset</a>
                            <?php endif; ?>
                        </form>
                        <span style="font-size: 10px; color: var(--text-muted);">
                            Showing <?= count($allMembers) ?> member(s)
                        </span>
                    </div>

                    <?php if (empty($allMembers)): ?>
                        <div class="box-body">
                            <span class="empty-note">No members matched your search or filter.</span>
                        </div>
                    <?php else: ?>
                        <div class="member-list">
                            <?php foreach ($allMembers as $m): ?>
                                <?php
                                $uid     = (int) $m['id'];
                                $uname   = $m['username'];
                                $urole   = $m['role'] ?? 'user';
                                $uAvatar = getSafeAvatar($m['avatar'] ?? '');
                                $uOnline = isUserOnline($m['last_seen'] ?? null);
                                $isMe    = ($isLoggedIn && $uid === $myId);
                                $isBlkByMe = isset($blockedByMe[$uid]);
                                $isBlkMe   = isset($blockedMe[$uid]);
                                $rel     = $relationMap[$uid] ?? 'none';
                                ?>
                                <div class="member-row">
                                    <div class="member-left">
                                        <a href="profile.php?u=<?= urlencode($uname) ?>" class="m-avatar" title="View <?= htmlspecialchars($uname, ENT_QUOTES, 'UTF-8') ?>'s profile">
                                            <?php if ($uAvatar !== ''): ?>
                                                <img src="<?= htmlspecialchars($uAvatar, ENT_QUOTES, 'UTF-8') ?>" alt="">
                                            <?php else: ?>
                                                <?= htmlspecialchars(strtoupper(substr($uname, 0, 1)), ENT_QUOTES, 'UTF-8') ?>
                                            <?php endif; ?>
                                        </a>
                                        <div class="m-info">
                                            <div class="m-name-line">
                                                <a href="profile.php?u=<?= urlencode($uname) ?>"><strong><?= htmlspecialchars($uname, ENT_QUOTES, 'UTF-8') ?></strong></a>
                                                <?= renderUserBadges($uname, $urole) ?>
                                                <?php if ($isMe): ?>
                                                    <span class="you-tag">YOU</span>
                                                <?php endif; ?>
                                            </div>
                                            <div class="m-meta">
                                                <span class="online-dot <?= $uOnline ? 'on' : 'off' ?>"></span>
                                                <?= $uOnline ? '<strong>Online</strong>' : 'Offline' ?>
                                                &bull; Joined <?= htmlspecialchars(date('Y-m-d', strtotime($m['created_at'])), ENT_QUOTES, 'UTF-8') ?>
                                            </div>
                                            <?php if (!empty($m['status_text'])): ?>
                                                <div class="m-status">&ldquo;<?= htmlspecialchars($m['status_text'], ENT_QUOTES, 'UTF-8') ?>&rdquo;</div>
                                            <?php endif; ?>
                                        </div>
                                    </div>

                                    <div class="member-actions">
                                        <a href="profile.php?u=<?= urlencode($uname) ?>" class="btn btn-xs">Profile</a>

                                        <?php if ($isLoggedIn && !$isMe): ?>
                                            <?php if ($isBlkByMe): ?>
                                                <form action="members.php?tab=all" method="post" style="margin: 0;">
                                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                                                    <input type="hidden" name="member_action" value="unblock_user">
                                                    <input type="hidden" name="target_id" value="<?= $uid ?>">
                                                    <input type="hidden" name="return_tab" value="all">
                                                    <button type="submit" class="btn btn-xs">Unblock</button>
                                                </form>
                                            <?php else: ?>
                                                <?php if (!$isBlkMe): ?>
                                                    <a href="messages.php?to=<?= urlencode($uname) ?>" class="btn btn-primary btn-xs">Message</a>

                                                    <?php if ($rel === 'none'): ?>
                                                        <form action="members.php?tab=all" method="post" style="margin: 0;">
                                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                                                            <input type="hidden" name="member_action" value="add_friend">
                                                            <input type="hidden" name="target_id" value="<?= $uid ?>">
                                                            <input type="hidden" name="return_tab" value="all">
                                                            <button type="submit" class="btn btn-approve btn-xs">+ Add Friend</button>
                                                        </form>
                                                    <?php elseif ($rel === 'sent'): ?>
                                                        <form action="members.php?tab=all" method="post" style="margin: 0;">
                                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                                                            <input type="hidden" name="member_action" value="cancel_request">
                                                            <input type="hidden" name="target_id" value="<?= $uid ?>">
                                                            <input type="hidden" name="return_tab" value="all">
                                                            <button type="submit" class="btn btn-xs">Cancel Req</button>
                                                        </form>
                                                    <?php elseif ($rel === 'received'): ?>
                                                        <form action="members.php?tab=all" method="post" style="margin: 0;">
                                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                                                            <input type="hidden" name="member_action" value="accept_friend">
                                                            <input type="hidden" name="target_id" value="<?= $uid ?>">
                                                            <input type="hidden" name="return_tab" value="all">
                                                            <button type="submit" class="btn btn-approve btn-xs">Accept</button>
                                                        </form>
                                                    <?php elseif ($rel === 'friends'): ?>
                                                        <button
                                                            type="button"
                                                            class="btn btn-xs"
                                                            onclick="openConfirmModal('remove_friend', <?= $uid ?>, <?= htmlspecialchars(json_encode($uname), ENT_QUOTES, 'UTF-8') ?>, 'all')"
                                                        >Unfriend</button>
                                                    <?php endif; ?>
                                                <?php endif; ?>

                                                <button
                                                    type="button"
                                                    class="btn btn-danger btn-xs"
                                                    onclick="openConfirmModal('block_user', <?= $uid ?>, <?= htmlspecialchars(json_encode($uname), ENT_QUOTES, 'UTF-8') ?>, 'all')"
                                                >Block</button>
                                                <button
                                                    type="button"
                                                    class="btn btn-warn btn-xs"
                                                    onclick="openReportModal(<?= $uid ?>, <?= htmlspecialchars(json_encode($uname), ENT_QUOTES, 'UTF-8') ?>, 'all')"
                                                >Report</button>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </section>

            <?php elseif ($activeTab === 'friends'): ?>
                <!-- ===========================================================
                     TAB 2: MY FRIENDS (Message, Unfriend, Block, Report)
                     =========================================================== -->
                <section class="box">
                    <div class="box-title">
                        <span>My Friends (<?= $friendsCount ?>)</span>
                        <a href="members.php?tab=all" style="color: #ffffff; font-weight: normal; font-size: 10px;">[Find More Friends]</a>
                    </div>

                    <?php if (empty($myFriends)): ?>
                        <div class="box-body">
                            <span class="empty-note">You don't have any friends added yet. Browse <a href="members.php?tab=all">All Members</a> to send friend requests!</span>
                        </div>
                    <?php else: ?>
                        <div class="member-list">
                            <?php foreach ($myFriends as $fr): ?>
                                <?php
                                $fid     = (int) $fr['id'];
                                $fname   = $fr['username'];
                                $frole   = $fr['role'] ?? 'user';
                                $fAvatar = getSafeAvatar($fr['avatar'] ?? '');
                                $fOnline = isUserOnline($fr['last_seen'] ?? null);
                                ?>
                                <div class="member-row">
                                    <div class="member-left">
                                        <a href="profile.php?u=<?= urlencode($fname) ?>" class="m-avatar">
                                            <?php if ($fAvatar !== ''): ?>
                                                <img src="<?= htmlspecialchars($fAvatar, ENT_QUOTES, 'UTF-8') ?>" alt="">
                                            <?php else: ?>
                                                <?= htmlspecialchars(strtoupper(substr($fname, 0, 1)), ENT_QUOTES, 'UTF-8') ?>
                                            <?php endif; ?>
                                        </a>
                                        <div class="m-info">
                                            <div class="m-name-line">
                                                <a href="profile.php?u=<?= urlencode($fname) ?>"><strong><?= htmlspecialchars($fname, ENT_QUOTES, 'UTF-8') ?></strong></a>
                                                <?= renderUserBadges($fname, $frole) ?>
                                            </div>
                                            <div class="m-meta">
                                                <span class="online-dot <?= $fOnline ? 'on' : 'off' ?>"></span>
                                                <?= $fOnline ? '<strong>Online Now</strong>' : 'Offline' ?>
                                            </div>
                                            <?php if (!empty($fr['status_text'])): ?>
                                                <div class="m-status">&ldquo;<?= htmlspecialchars($fr['status_text'], ENT_QUOTES, 'UTF-8') ?>&rdquo;</div>
                                            <?php endif; ?>
                                        </div>
                                    </div>

                                    <div class="member-actions">
                                        <a href="messages.php?to=<?= urlencode($fname) ?>" class="btn btn-primary btn-xs">Message</a>
                                        <a href="profile.php?u=<?= urlencode($fname) ?>" class="btn btn-xs">Profile</a>
                                        <button
                                            type="button"
                                            class="btn btn-xs"
                                            onclick="openConfirmModal('remove_friend', <?= $fid ?>, <?= htmlspecialchars(json_encode($fname), ENT_QUOTES, 'UTF-8') ?>, 'friends')"
                                        >Remove Friend</button>
                                        <button
                                            type="button"
                                            class="btn btn-danger btn-xs"
                                            onclick="openConfirmModal('block_user', <?= $fid ?>, <?= htmlspecialchars(json_encode($fname), ENT_QUOTES, 'UTF-8') ?>, 'friends')"
                                        >Block</button>
                                        <button
                                            type="button"
                                            class="btn btn-warn btn-xs"
                                            onclick="openReportModal(<?= $fid ?>, <?= htmlspecialchars(json_encode($fname), ENT_QUOTES, 'UTF-8') ?>, 'friends')"
                                        >Report</button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </section>

            <?php elseif ($activeTab === 'requests'): ?>
                <!-- ===========================================================
                     TAB 3: FRIEND REQUESTS (Incoming & Sent)
                     =========================================================== -->
                <section class="box">
                    <div class="box-title">
                        <span>Incoming Friend Requests (<?= $incomingCount ?>)</span>
                    </div>
                    <?php if (empty($incomingRequests)): ?>
                        <div class="box-body">
                            <span class="empty-note">You have no incoming friend requests right now.</span>
                        </div>
                    <?php else: ?>
                        <div class="member-list">
                            <?php foreach ($incomingRequests as $req): ?>
                                <?php
                                $rid     = (int) $req['id'];
                                $rname   = $req['username'];
                                $rAvatar = getSafeAvatar($req['avatar'] ?? '');
                                ?>
                                <div class="member-row">
                                    <div class="member-left">
                                        <a href="profile.php?u=<?= urlencode($rname) ?>" class="m-avatar">
                                            <?php if ($rAvatar !== ''): ?>
                                                <img src="<?= htmlspecialchars($rAvatar, ENT_QUOTES, 'UTF-8') ?>" alt="">
                                            <?php else: ?>
                                                <?= htmlspecialchars(strtoupper(substr($rname, 0, 1)), ENT_QUOTES, 'UTF-8') ?>
                                            <?php endif; ?>
                                        </a>
                                        <div class="m-info">
                                            <div class="m-name-line">
                                                <a href="profile.php?u=<?= urlencode($rname) ?>"><strong><?= htmlspecialchars($rname, ENT_QUOTES, 'UTF-8') ?></strong></a>
                                                <?= renderUserBadges($rname, $req['role'] ?? 'user') ?>
                                            </div>
                                            <div class="m-meta">
                                                Sent on <?= htmlspecialchars(date('Y-m-d H:i', strtotime($req['requested_at'])), ENT_QUOTES, 'UTF-8') ?>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="member-actions">
                                        <form action="members.php?tab=requests" method="post" style="margin: 0;">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                                            <input type="hidden" name="member_action" value="accept_friend">
                                            <input type="hidden" name="target_id" value="<?= $rid ?>">
                                            <input type="hidden" name="return_tab" value="requests">
                                            <button type="submit" class="btn btn-approve btn-xs">Accept</button>
                                        </form>
                                        <form action="members.php?tab=requests" method="post" style="margin: 0;">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                                            <input type="hidden" name="member_action" value="decline_friend">
                                            <input type="hidden" name="target_id" value="<?= $rid ?>">
                                            <input type="hidden" name="return_tab" value="requests">
                                            <button type="submit" class="btn btn-xs">Decline</button>
                                        </form>
                                        <button
                                            type="button"
                                            class="btn btn-danger btn-xs"
                                            onclick="openConfirmModal('block_user', <?= $rid ?>, <?= htmlspecialchars(json_encode($rname), ENT_QUOTES, 'UTF-8') ?>, 'requests')"
                                        >Block</button>
                                        <button
                                            type="button"
                                            class="btn btn-warn btn-xs"
                                            onclick="openReportModal(<?= $rid ?>, <?= htmlspecialchars(json_encode($rname), ENT_QUOTES, 'UTF-8') ?>, 'requests')"
                                        >Report</button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </section>

                <section class="box">
                    <div class="box-title">
                        <span>Sent Friend Requests (<?= count($outgoingRequests) ?>)</span>
                    </div>
                    <?php if (empty($outgoingRequests)): ?>
                        <div class="box-body">
                            <span class="empty-note">You have no pending sent requests.</span>
                        </div>
                    <?php else: ?>
                        <div class="member-list">
                            <?php foreach ($outgoingRequests as $out): ?>
                                <?php
                                $oid     = (int) $out['id'];
                                $oname   = $out['username'];
                                $oAvatar = getSafeAvatar($out['avatar'] ?? '');
                                ?>
                                <div class="member-row">
                                    <div class="member-left">
                                        <a href="profile.php?u=<?= urlencode($oname) ?>" class="m-avatar">
                                            <?php if ($oAvatar !== ''): ?>
                                                <img src="<?= htmlspecialchars($oAvatar, ENT_QUOTES, 'UTF-8') ?>" alt="">
                                            <?php else: ?>
                                                <?= htmlspecialchars(strtoupper(substr($oname, 0, 1)), ENT_QUOTES, 'UTF-8') ?>
                                            <?php endif; ?>
                                        </a>
                                        <div class="m-info">
                                            <div class="m-name-line">
                                                <a href="profile.php?u=<?= urlencode($oname) ?>"><strong><?= htmlspecialchars($oname, ENT_QUOTES, 'UTF-8') ?></strong></a>
                                                <?= renderUserBadges($oname, $out['role'] ?? 'user') ?>
                                            </div>
                                            <div class="m-meta">
                                                Waiting for response &bull; Sent <?= htmlspecialchars(date('Y-m-d H:i', strtotime($out['requested_at'])), ENT_QUOTES, 'UTF-8') ?>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="member-actions">
                                        <a href="profile.php?u=<?= urlencode($oname) ?>" class="btn btn-xs">Profile</a>
                                        <form action="members.php?tab=requests" method="post" style="margin: 0;">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                                            <input type="hidden" name="member_action" value="cancel_request">
                                            <input type="hidden" name="target_id" value="<?= $oid ?>">
                                            <input type="hidden" name="return_tab" value="requests">
                                            <button type="submit" class="btn btn-xs">Cancel Request</button>
                                        </form>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </section>

            <?php elseif ($activeTab === 'blocked'): ?>
                <!-- ===========================================================
                     TAB 4: BLOCKED USERS
                     =========================================================== -->
                <section class="box">
                    <div class="box-title">
                        <span>Blocked Users (<?= $blockedCount ?>)</span>
                    </div>
                    <?php if (empty($blockedUsers)): ?>
                        <div class="box-body">
                            <span class="empty-note">You haven't blocked any users.</span>
                        </div>
                    <?php else: ?>
                        <div class="member-list">
                            <?php foreach ($blockedUsers as $bu): ?>
                                <?php
                                $bid     = (int) $bu['id'];
                                $bname   = $bu['username'];
                                $bAvatar = getSafeAvatar($bu['avatar'] ?? '');
                                ?>
                                <div class="member-row">
                                    <div class="member-left">
                                        <div class="m-avatar">
                                            <?php if ($bAvatar !== ''): ?>
                                                <img src="<?= htmlspecialchars($bAvatar, ENT_QUOTES, 'UTF-8') ?>" alt="">
                                            <?php else: ?>
                                                <?= htmlspecialchars(strtoupper(substr($bname, 0, 1)), ENT_QUOTES, 'UTF-8') ?>
                                            <?php endif; ?>
                                        </div>
                                        <div class="m-info">
                                            <div class="m-name-line">
                                                <strong><?= htmlspecialchars($bname, ENT_QUOTES, 'UTF-8') ?></strong>
                                                <?= renderUserBadges($bname, $bu['role'] ?? 'user') ?>
                                            </div>
                                            <div class="m-meta">
                                                Blocked on <?= htmlspecialchars(date('Y-m-d H:i', strtotime($bu['blocked_at'])), ENT_QUOTES, 'UTF-8') ?>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="member-actions">
                                        <form action="members.php?tab=blocked" method="post" style="margin: 0;">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                                            <input type="hidden" name="member_action" value="unblock_user">
                                            <input type="hidden" name="target_id" value="<?= $bid ?>">
                                            <input type="hidden" name="return_tab" value="blocked">
                                            <button type="submit" class="btn btn-xs">Unblock</button>
                                        </form>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </section>
            <?php endif; ?>

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

    <!-- Custom Report User Modal -->
    <div class="modal-overlay" id="reportModal" role="dialog" aria-modal="true" aria-labelledby="reportModalTitle">
        <div class="modal-box">
            <div class="box-title">
                <span id="reportModalTitle">Report User</span>
                <button type="button" class="btn btn-xs" onclick="closeModals()">&times;</button>
            </div>
            <div class="modal-body">
                <form action="members.php" method="post" id="reportForm">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="member_action" value="report_user">
                    <input type="hidden" name="target_id" id="reportTargetId" value="">
                    <input type="hidden" name="return_tab" id="reportReturnTab" value="all">

                    <p style="margin-top: 0;">
                        Reporting user: <strong id="reportTargetName"></strong>
                    </p>

                    <label for="report_reason">Reason</label>
                    <select name="report_reason" id="report_reason" required>
                        <option value="Harassment / Bullying">Harassment / Bullying</option>
                        <option value="Spam / Scam">Spam / Scam</option>
                        <option value="Offensive Profile or Avatar">Offensive Profile or Avatar</option>
                        <option value="Impersonation">Impersonation</option>
                        <option value="Rule Violation / Other">Rule Violation / Other</option>
                    </select>

                    <label for="report_details">Details (optional, max 300 chars)</label>
                    <textarea name="report_details" id="report_details" maxlength="300" placeholder="Briefly describe what happened..."></textarea>

                    <div class="modal-actions">
                        <button type="button" class="btn btn-xs" onclick="closeModals()">Cancel</button>
                        <button type="submit" class="btn btn-warn btn-xs">Submit Report</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Custom Confirm Modal (Unfriend / Block) -->
    <div class="modal-overlay" id="confirmModal" role="dialog" aria-modal="true" aria-labelledby="confirmModalTitle">
        <div class="modal-box">
            <div class="box-title">
                <span id="confirmModalTitle">Confirm Action</span>
                <button type="button" class="btn btn-xs" onclick="closeModals()">&times;</button>
            </div>
            <div class="modal-body">
                <p id="confirmModalText" style="margin-top: 0;"></p>
                <form action="members.php" method="post" id="confirmForm">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="member_action" id="confirmActionInput" value="">
                    <input type="hidden" name="target_id" id="confirmTargetId" value="">
                    <input type="hidden" name="return_tab" id="confirmReturnTab" value="all">

                    <div class="modal-actions">
                        <button type="button" class="btn btn-xs" onclick="closeModals()">Cancel</button>
                        <button type="submit" class="btn btn-danger btn-xs" id="confirmSubmitBtn">Confirm</button>
                    </div>
                </form>
            </div>
        </div>
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

        /* Custom Report & Confirm Modals */
        const reportModal  = document.getElementById('reportModal');
        const confirmModal = document.getElementById('confirmModal');

        function closeModals() {
            reportModal.classList.remove('open');
            confirmModal.classList.remove('open');
        }

        function openReportModal(userId, username, returnTab) {
            document.getElementById('reportTargetId').value = userId;
            document.getElementById('reportTargetName').textContent = username;
            document.getElementById('reportReturnTab').value = returnTab;
            document.getElementById('report_details').value = '';
            document.getElementById('reportForm').action = 'members.php?tab=' + encodeURIComponent(returnTab);
            reportModal.classList.add('open');
        }

        function openConfirmModal(action, userId, username, returnTab) {
            document.getElementById('confirmActionInput').value = action;
            document.getElementById('confirmTargetId').value = userId;
            document.getElementById('confirmReturnTab').value = returnTab;
            document.getElementById('confirmForm').action = 'members.php?tab=' + encodeURIComponent(returnTab);

            if (action === 'block_user') {
                document.getElementById('confirmModalTitle').textContent = 'Block User';
                document.getElementById('confirmModalText').textContent =
                    'Are you sure you want to block ' + username + '? This will also remove them from your friends list.';
                document.getElementById('confirmSubmitBtn').textContent = 'Block User';
            } else {
                document.getElementById('confirmModalTitle').textContent = 'Remove Friend';
                document.getElementById('confirmModalText').textContent =
                    'Are you sure you want to remove ' + username + ' from your friends list?';
                document.getElementById('confirmSubmitBtn').textContent = 'Remove Friend';
            }

            confirmModal.classList.add('open');
        }

        // Close modal when clicking outside the dialog box or pressing Escape
        [reportModal, confirmModal].forEach(overlay => {
            overlay.addEventListener('click', (e) => {
                if (e.target === overlay) closeModals();
            });
        });

        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') closeModals();
        });
    </script>

</body>
</html>