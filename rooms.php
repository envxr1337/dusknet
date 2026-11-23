<?php
session_start();
require_once __DIR__ . '/db.php';

// Generate CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$isLoggedIn = !empty($_SESSION['user_id']);
$myId       = $isLoggedIn ? (int) $_SESSION['user_id'] : 0;
$myRole     = 'user';

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
 * Normalizes text for basic profanity/impersonation checks on room names & topics.
 */
function normalizeRoomFilter(string $text): string
{
    $text = mb_strtolower($text, 'UTF-8');
    $map = [
        '0' => 'o', '1' => 'i', '3' => 'e', '4' => 'a',
        '5' => 's', '7' => 't', '8' => 'b', '9' => 'g',
        '$' => 's', '@' => 'a',
        'ı' => 'i', 'ş' => 's', 'ğ' => 'g', 'ü' => 'u', 'ö' => 'o', 'ç' => 'c',
    ];
    $text = strtr($text, $map);
    $letters = preg_replace('/[^a-z\s]/', ' ', $text);
    return trim(preg_replace('/(.)\1+/', '$1', $letters));
}

/**
 * Returns true if room name or topic contains blocked profanity/slurs.
 */
function hasBlockedRoomText(string $text): bool
{
    $compact = str_replace(' ', '', normalizeRoomFilter($text));
    $blocked = [
        'nigger', 'nigga', 'faggot', 'retard', 'chink', 'kike', 'spic',
        'fuck', 'fck', 'shit', 'bitch', 'cunt', 'whore', 'slut', 'porn', 'rape', 'nazi', 'hitler',
        'siktir', 'sikim', 'sikeyim', 'orospu', 'amcik', 'amk', 'yarrak', 'yarak',
        'gavat', 'pezevenk', 'kahpe', 'gotveren', 'ibne', 'ananisik',
        'putain', 'scheisse', 'arschloch', 'vaffanculo', 'blyat', 'pizdec',
    ];
    foreach ($blocked as $term) {
        if (str_contains($compact, $term)) {
            return true;
        }
    }
    return false;
}

/**
 * Ensures chat_rooms, room_presence, and chat_messages tables exist and seeds official rooms.
 */
function ensureRoomsSchema(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS chat_rooms (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            slug VARCHAR(32) NOT NULL UNIQUE,
            name VARCHAR(40) NOT NULL,
            topic VARCHAR(160) NOT NULL,
            category ENUM('general', 'music', 'games', 'tech', 'community') NOT NULL DEFAULT 'community',
            is_official TINYINT(1) NOT NULL DEFAULT 0,
            password_hash VARCHAR(255) NULL DEFAULT NULL,
            created_by INT UNSIGNED NULL DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_room_cat (category),
            CONSTRAINT fk_room_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS room_presence (
            room_id INT UNSIGNED NOT NULL,
            user_id INT UNSIGNED NOT NULL,
            last_ping TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (room_id, user_id),
            INDEX idx_presence_ping (last_ping),
            CONSTRAINT fk_pres_room FOREIGN KEY (room_id) REFERENCES chat_rooms(id) ON DELETE CASCADE,
            CONSTRAINT fk_pres_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS chat_messages (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            room_id INT UNSIGNED NOT NULL,
            user_id INT UNSIGNED NOT NULL,
            message VARCHAR(400) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_msg_room_time (room_id, created_at),
            CONSTRAINT fk_msg_room FOREIGN KEY (room_id) REFERENCES chat_rooms(id) ON DELETE CASCADE,
            CONSTRAINT fk_msg_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    // Seed official DuskNet rooms if they do not exist
    $defaultRooms = [
        ['lounge',    '#lounge',    'General daily chat & hangouts',         'general'],
        ['music',     '#music',     'Songs, albums, playlists & radio',      'music'],
        ['games',     '#games',     'PC, retro, console & indie games',      'games'],
        ['latenight', '#latenight', 'Late-night talks, chill & insomnia',    'general'],
        ['tech',      '#tech',      'Coding, web design & retro hardware',   'tech'],
    ];

    $seedStmt = $pdo->prepare(
        'INSERT IGNORE INTO chat_rooms (slug, name, topic, category, is_official) VALUES (?, ?, ?, ?, 1)'
    );
    foreach ($defaultRooms as $r) {
        $seedStmt->execute($r);
    }
}

$errors  = [];
$success = '';

$searchQuery = trim($_GET['q'] ?? '');
$category    = $_GET['cat'] ?? 'all';
$allowedCats = ['all', 'general', 'music', 'games', 'tech', 'community'];
if (!in_array($category, $allowedCats, true)) {
    $category = 'all';
}

$rooms         = [];
$totalRooms    = 0;
$totalChatters = 0;
$totalMessages = 0;

// Keep create-room form values on validation error
$formSlug     = '';
$formTopic    = '';
$formCategory = 'community';

try {
    ensureRoomsSchema($pdo);

    // Sync logged-in user activity and role
    if ($isLoggedIn) {
        $pdo->prepare('UPDATE users SET last_seen = NOW() WHERE id = ?')->execute([$myId]);
        $meStmt = $pdo->prepare('SELECT username, role FROM users WHERE id = ? LIMIT 1');
        $meStmt->execute([$myId]);
        $me = $meStmt->fetch();
        if ($me) {
            $_SESSION['username'] = $me['username'];
            $_SESSION['role']     = $me['role'];
            $myRole               = strtolower(trim($me['role']));
        }
    }

    // Clean up stale room presence (older than 2 minutes)
    $pdo->exec('DELETE FROM room_presence WHERE last_ping < (NOW() - INTERVAL 2 MINUTE)');

    // =========================================================================
    // Handle POST Actions: Create Room / Delete Room
    // =========================================================================
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['room_action'])) {
        $token  = $_POST['csrf_token'] ?? '';
        $action = $_POST['room_action'];

        if (!$isLoggedIn) {
            $errors[] = 'You must be logged in to manage chat rooms.';
        } elseif (!hash_equals($_SESSION['csrf_token'], $token)) {
            $errors[] = 'Security check failed (invalid token). Please refresh and try again.';
        }

        // 1. Create a new community room
        elseif ($action === 'create_room') {
            $rawSlug      = trim($_POST['room_slug'] ?? '');
            $formSlug     = ltrim(strtolower($rawSlug), '#');
            $formTopic    = trim($_POST['room_topic'] ?? '');
            $formCategory = $_POST['room_category'] ?? 'community';
            $roomPass     = trim($_POST['room_password'] ?? '');

            $validCreateCats = ['general', 'music', 'games', 'tech', 'community'];
            if (!in_array($formCategory, $validCreateCats, true)) {
                $formCategory = 'community';
            }

            if ($formSlug === '' || !preg_match('/^[a-z0-9_-]{3,24}$/', $formSlug)) {
                $errors[] = 'Room name must be 3-24 characters (lowercase letters, numbers, dash, or underscore).';
            } elseif (hasBlockedRoomText($formSlug) || hasBlockedRoomText($formTopic)) {
                $errors[] = 'Room name or topic contains prohibited or offensive language.';
            }

            if ($formTopic === '' || mb_strlen($formTopic) < 4 || mb_strlen($formTopic) > 140) {
                $errors[] = 'Room topic must be between 4 and 140 characters.';
            }

            if ($roomPass !== '' && mb_strlen($roomPass) < 4) {
                $errors[] = 'Optional room password must be at least 4 characters long.';
            }

            // Limit regular users to max 2 custom rooms (VIP: 4, Mod/Admin: unlimited)
            if (empty($errors) && !in_array($myRole, ['admin', 'mod'], true)) {
                $limit = ($myRole === 'vip') ? 4 : 2;
                $cntStmt = $pdo->prepare('SELECT COUNT(*) FROM chat_rooms WHERE created_by = ?');
                $cntStmt->execute([$myId]);
                if ((int) $cntStmt->fetchColumn() >= $limit) {
                    $errors[] = "You can only create up to {$limit} active rooms at a time. Delete an existing room first.";
                }
            }

            if (empty($errors)) {
                $dupStmt = $pdo->prepare('SELECT id FROM chat_rooms WHERE slug = ? LIMIT 1');
                $dupStmt->execute([$formSlug]);
                if ($dupStmt->fetch()) {
                    $errors[] = 'A room named #' . $formSlug . ' already exists.';
                } else {
                    $roomName = '#' . $formSlug;
                    $passHash = $roomPass !== '' ? password_hash($roomPass, PASSWORD_DEFAULT) : null;
                    $isOfficial = ($myRole === 'admin' && isset($_POST['mark_official'])) ? 1 : 0;

                    $ins = $pdo->prepare(
                        'INSERT INTO chat_rooms (slug, name, topic, category, is_official, password_hash, created_by)
                         VALUES (?, ?, ?, ?, ?, ?, ?)'
                    );
                    $ins->execute([
                        $formSlug,
                        $roomName,
                        $formTopic,
                        $formCategory,
                        $isOfficial,
                        $passHash,
                        $myId,
                    ]);

                    $success   = "Room {$roomName} created! You can join it now.";
                    $formSlug  = '';
                    $formTopic = '';
                }
            }
        }

        // 2. Delete a custom room (owner or admin/mod only; official rooms only by admin)
        elseif ($action === 'delete_room') {
            $roomId = (int) ($_POST['room_id'] ?? 0);
            if ($roomId > 0) {
                $rStmt = $pdo->prepare('SELECT id, name, is_official, created_by FROM chat_rooms WHERE id = ? LIMIT 1');
                $rStmt->execute([$roomId]);
                $roomRow = $rStmt->fetch();

                if (!$roomRow) {
                    $errors[] = 'Room not found.';
                } elseif ((int) $roomRow['is_official'] === 1 && $myRole !== 'admin') {
                    $errors[] = 'Official DuskNet rooms cannot be deleted.';
                } elseif ((int) $roomRow['created_by'] !== $myId && !in_array($myRole, ['admin', 'mod'], true)) {
                    $errors[] = 'You do not have permission to delete this room.';
                } else {
                    $pdo->prepare('DELETE FROM chat_rooms WHERE id = ?')->execute([$roomId]);
                    $success = 'Room ' . $roomRow['name'] . ' has been removed.';
                }
            }
        }
    }

    // =========================================================================
    // Load Directory Statistics & Filtered Rooms List
    // =========================================================================
    $totalRooms    = (int) $pdo->query('SELECT COUNT(*) FROM chat_rooms')->fetchColumn();
    $totalChatters = (int) $pdo->query('SELECT COUNT(DISTINCT user_id) FROM room_presence')->fetchColumn();
    $totalMessages = (int) $pdo->query('SELECT COUNT(*) FROM chat_messages')->fetchColumn();

    $where  = [];
    $params = [];

    if ($category !== 'all') {
        $where[]  = 'r.category = ?';
        $params[] = $category;
    }
    if ($searchQuery !== '') {
        $where[]  = '(r.name LIKE ? OR r.topic LIKE ?)';
        $params[] = '%' . $searchQuery . '%';
        $params[] = '%' . $searchQuery . '%';
    }

    $whereSql = !empty($where) ? ('WHERE ' . implode(' AND ', $where)) : '';

    $roomsStmt = $pdo->prepare(
        "SELECT r.id, r.slug, r.name, r.topic, r.category, r.is_official,
                (r.password_hash IS NOT NULL) AS is_locked,
                r.created_by, r.created_at,
                u.username AS creator_name, u.role AS creator_role,
                (SELECT COUNT(*) FROM room_presence p WHERE p.room_id = r.id) AS active_users,
                (SELECT COUNT(*) FROM chat_messages m WHERE m.room_id = r.id) AS msg_count
         FROM chat_rooms r
         LEFT JOIN users u ON u.id = r.created_by
         {$whereSql}
         ORDER BY r.is_official DESC, active_users DESC, r.created_at DESC"
    );
    $roomsStmt->execute($params);
    $rooms = $roomsStmt->fetchAll();
} catch (Throwable $e) {
    $errors[] = 'Unable to load chat rooms right now. Please try again later.';
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="blue">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Chat Rooms - DuskNet</title>
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

        /* Two-Column Content */
        .content {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            padding: 12px;
        }

        .main-col { flex: 1 1 460px; min-width: 0; }
        .side-col { flex: 0 1 230px; min-width: 210px; margin: 0 auto; }

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
        .msg-box ul { margin: 3px 0 0 16px; padding: 0; }

        /* Category Filter Bar */
        .cat-bar {
            display: flex;
            flex-wrap: wrap;
            justify-content: space-between;
            align-items: center;
            gap: 6px;
            padding: 6px 8px;
            background-color: var(--bg-soft);
            border-bottom: 1px solid var(--border);
        }

        .cat-links {
            display: flex;
            flex-wrap: wrap;
            gap: 4px;
        }

        .cat-pill {
            padding: 2px 7px;
            font-size: 10px;
            font-weight: bold;
            border: 1px solid var(--border-strong);
            background: linear-gradient(to bottom, var(--btn-top), var(--btn-bot));
            color: var(--btn-text);
            text-decoration: none;
        }

        .cat-pill:hover { text-decoration: none; filter: brightness(0.96); }

        .cat-pill.active {
            background: linear-gradient(to bottom, var(--btn-primary-top), var(--btn-primary-bot));
            color: #ffffff;
            border-color: var(--btn-primary-border);
        }

        /* Rooms Table */
        table.rooms-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 11px;
        }

        table.rooms-table th {
            background-color: var(--bg-soft);
            border-bottom: 1px solid var(--border);
            text-align: left;
            padding: 5px 8px;
            color: var(--text);
            font-size: 10px;
        }

        table.rooms-table td {
            padding: 7px 8px;
            border-bottom: 1px dotted var(--border);
            vertical-align: middle;
        }

        table.rooms-table tbody tr:hover td {
            background-color: var(--bg-hover);
        }

        table.rooms-table tr:last-child td {
            border-bottom: none;
        }

        .room-name-link {
            font-weight: bold;
            font-size: 12px;
            color: var(--link);
        }

        .room-topic {
            color: var(--text-muted);
            font-size: 10px;
            margin-top: 2px;
        }

        .room-meta {
            font-size: 9px;
            color: var(--text-muted);
            margin-top: 2px;
        }

        .room-badge {
            display: inline-block;
            padding: 0 4px;
            margin-left: 3px;
            font-size: 9px;
            font-weight: bold;
            border: 1px solid;
            vertical-align: 1px;
        }

        .rb-official {
            background-color: var(--bg-soft);
            color: var(--link);
            border-color: var(--border-strong);
        }

        .rb-locked {
            background-color: #fff4d6;
            color: #8a5a00;
            border-color: #d4a638;
        }

        .active-count {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            font-weight: bold;
        }

        .dot-live {
            width: 7px;
            height: 7px;
            border-radius: 50%;
            display: inline-block;
        }
        .dot-live.on  { background-color: #27a844; border: 1px solid #166e2a; }
        .dot-live.off { background-color: #9aa3ad; border: 1px solid #6b737d; }

        /* Badges */
        .role-pill {
            display: inline-block;
            margin-left: 3px;
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
            background-color: rgba(255, 255, 255, 0.16);
            color: #ffffff;
            border: 1px solid rgba(255, 255, 255, 0.45);
            white-space: nowrap;
            vertical-align: 1px;
        }

        /* Forms */
        .form-row { margin-bottom: 8px; }
        .form-row label { display: block; font-weight: bold; margin-bottom: 2px; font-size: 11px; }
        .form-row input[type="text"],
        .form-row input[type="password"],
        .form-row select {
            width: 100%;
            padding: 4px 6px;
            border: 1px solid var(--input-border);
            background-color: var(--input-bg);
            color: var(--text);
            font-family: inherit;
            font-size: 11px;
        }
        .form-row input:focus, .form-row select:focus { outline: none; border-color: var(--header-top); }
        .field-hint { font-size: 10px; color: var(--text-muted); margin-top: 2px; }

        /* Stats List */
        .stats-list { list-style: none; margin: 0; padding: 0; font-size: 11px; }
        .stats-list li {
            display: flex;
            justify-content: space-between;
            padding: 4px 0;
            border-bottom: 1px dotted var(--border);
        }
        .stats-list li:last-child { border-bottom: none; }

        /* Buttons */
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
        .btn-danger {
            background: linear-gradient(to bottom, #c94b4b, #992d2d);
            color: #ffffff;
            border-color: #701d1d;
        }

        .empty-note { color: var(--text-muted); font-style: italic; }

        /* Classic Modal for Delete Room Confirmation */
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
            max-width: 340px;
            background-color: var(--bg-box);
            border: 1px solid var(--border-strong);
            box-shadow: 3px 3px 0 rgba(0, 0, 0, 0.28);
        }
        .modal-body { padding: 10px 12px; }
        .modal-actions {
            display: flex;
            justify-content: flex-end;
            gap: 6px;
            margin-top: 10px;
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
            <form action="rooms.php" method="get" class="header-search">
                <input
                    type="text"
                    name="q"
                    value="<?= htmlspecialchars($searchQuery, ENT_QUOTES, 'UTF-8') ?>"
                    placeholder="Search chat rooms..."
                    aria-label="Search chat rooms"
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

        <!-- Main Two-Column Content -->
        <main class="content">

            <!-- Left Column: Rooms Directory -->
            <div class="main-col">

                <?php if (!empty($errors)): ?>
                    <div class="msg-box msg-error">
                        <strong>Error:</strong>
                        <ul>
                            <?php foreach ($errors as $err): ?>
                                <li><?= htmlspecialchars($err, ENT_QUOTES, 'UTF-8') ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <?php if ($success !== ''): ?>
                    <div class="msg-box msg-success">
                        <?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?>
                    </div>
                <?php endif; ?>

                <section class="box">
                    <div class="box-title">
                        <span>Public Chat Rooms (<?= count($rooms) ?>)</span>
                        <?php if ($searchQuery !== '' || $category !== 'all'): ?>
                            <a href="rooms.php" style="color: #ffffff; font-weight: normal; font-size: 10px;">[Reset Filter]</a>
                        <?php endif; ?>
                    </div>

                    <!-- Category Filter Pills -->
                    <div class="cat-bar">
                        <div class="cat-links">
                            <?php
                            $catLabels = [
                                'all'       => 'All',
                                'general'   => 'General',
                                'music'     => 'Music',
                                'games'     => 'Games',
                                'tech'      => 'Tech',
                                'community' => 'Community',
                            ];
                            foreach ($catLabels as $key => $label):
                                $url = 'rooms.php?cat=' . urlencode($key) . ($searchQuery !== '' ? '&q=' . urlencode($searchQuery) : '');
                            ?>
                                <a href="<?= htmlspecialchars($url, ENT_QUOTES, 'UTF-8') ?>" class="cat-pill <?= $category === $key ? 'active' : '' ?>">
                                    <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <?php if (empty($rooms)): ?>
                        <div class="box-body">
                            <span class="empty-note">No chat rooms match your search or category filter.</span>
                        </div>
                    <?php else: ?>
                        <table class="rooms-table">
                            <thead>
                                <tr>
                                    <th>Room &amp; Topic</th>
                                    <th style="width: 68px;">Online</th>
                                    <th style="width: 60px;">Msgs</th>
                                    <th style="width: 112px; text-align: right;">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($rooms as $room): ?>
                                    <?php
                                    $rId        = (int) $room['id'];
                                    $rSlug      = $room['slug'];
                                    $rName      = $room['name'];
                                    $rOfficial  = ((int) $room['is_official'] === 1);
                                    $rLocked    = ((int) $room['is_locked'] === 1);
                                    $rActive    = (int) $room['active_users'];
                                    $rMsgs      = (int) $room['msg_count'];
                                    $canDelete  = $isLoggedIn && (
                                        (!$rOfficial && (int) $room['created_by'] === $myId) ||
                                        (!$rOfficial && in_array($myRole, ['admin', 'mod'], true)) ||
                                        ($rOfficial && $myRole === 'admin')
                                    );
                                    ?>
                                    <tr>
                                        <td>
                                            <div>
                                                <a href="room.php?id=<?= urlencode($rSlug) ?>" class="room-name-link">
                                                    <?= htmlspecialchars($rName, ENT_QUOTES, 'UTF-8') ?>
                                                </a>
                                                <?php if ($rOfficial): ?>
                                                    <span class="room-badge rb-official">Official</span>
                                                <?php endif; ?>
                                                <?php if ($rLocked): ?>
                                                    <span class="room-badge rb-locked" title="Password required">&#128274; Locked</span>
                                                <?php endif; ?>
                                            </div>
                                            <div class="room-topic">
                                                <?= htmlspecialchars($room['topic'], ENT_QUOTES, 'UTF-8') ?>
                                            </div>
                                            <?php if (!$rOfficial && !empty($room['creator_name'])): ?>
                                                <div class="room-meta">
                                                    Created by <a href="profile.php?u=<?= urlencode($room['creator_name']) ?>"><?= htmlspecialchars($room['creator_name'], ENT_QUOTES, 'UTF-8') ?></a>
                                                    &bull; <?= htmlspecialchars(ucfirst($room['category']), ENT_QUOTES, 'UTF-8') ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="active-count">
                                                <span class="dot-live <?= $rActive > 0 ? 'on' : 'off' ?>"></span>
                                                <?= $rActive ?>
                                            </span>
                                        </td>
                                        <td style="color: var(--text-muted);"><?= $rMsgs ?></td>
                                        <td style="text-align: right; white-space: nowrap;">
                                            <a href="room.php?id=<?= urlencode($rSlug) ?>" class="btn btn-primary btn-xs">Join &raquo;</a>
                                            <?php if ($canDelete): ?>
                                                <button
                                                    type="button"
                                                    class="btn btn-danger btn-xs"
                                                    title="Delete room"
                                                    onclick="openDeleteRoomModal(<?= $rId ?>, <?= htmlspecialchars(json_encode($rName), ENT_QUOTES, 'UTF-8') ?>)"
                                                >&times;</button>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </section>

            </div>

            <!-- Right Column: Create Room + Live Stats -->
            <aside class="side-col">

                <!-- Create a Room Box -->
                <section class="box">
                    <div class="box-title">Create a Chat Room</div>
                    <div class="box-body">
                        <?php if ($isLoggedIn): ?>
                            <form action="rooms.php" method="post" autocomplete="off">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="room_action" value="create_room">

                                <div class="form-row">
                                    <label for="room_slug">Room Name (without #)</label>
                                    <input
                                        type="text"
                                        id="room_slug"
                                        name="room_slug"
                                        maxlength="24"
                                        placeholder="e.g. anime_chat"
                                        value="<?= htmlspecialchars($formSlug, ENT_QUOTES, 'UTF-8') ?>"
                                        required
                                    >
                                    <div class="field-hint">3-24 chars (a-z, 0-9, _, -).</div>
                                </div>

                                <div class="form-row">
                                    <label for="room_topic">Topic / Description</label>
                                    <input
                                        type="text"
                                        id="room_topic"
                                        name="room_topic"
                                        maxlength="140"
                                        placeholder="What is this room about?"
                                        value="<?= htmlspecialchars($formTopic, ENT_QUOTES, 'UTF-8') ?>"
                                        required
                                    >
                                </div>

                                <div class="form-row">
                                    <label for="room_category">Category</label>
                                    <select id="room_category" name="room_category">
                                        <option value="community" <?= $formCategory === 'community' ? 'selected' : '' ?>>Community</option>
                                        <option value="general" <?= $formCategory === 'general' ? 'selected' : '' ?>>General</option>
                                        <option value="music" <?= $formCategory === 'music' ? 'selected' : '' ?>>Music</option>
                                        <option value="games" <?= $formCategory === 'games' ? 'selected' : '' ?>>Games</option>
                                        <option value="tech" <?= $formCategory === 'tech' ? 'selected' : '' ?>>Tech</option>
                                    </select>
                                </div>

                                <div class="form-row">
                                    <label for="room_password">Password (Optional)</label>
                                    <input type="password" id="room_password" name="room_password" maxlength="50" placeholder="Leave blank for public">
                                    <div class="field-hint">Set a password to make a private room.</div>
                                </div>

                                <?php if ($myRole === 'admin'): ?>
                                    <div class="form-row">
                                        <label style="font-weight: normal;">
                                            <input type="checkbox" name="mark_official" value="1">
                                            Mark as Official DuskNet Room
                                        </label>
                                    </div>
                                <?php endif; ?>

                                <button type="submit" class="btn btn-primary" style="width: 100%;">Create Room</button>
                            </form>
                        <?php else: ?>
                            <p style="margin-top: 0; margin-bottom: 8px;">
                                <a href="login.php"><strong>Login</strong></a> or <a href="register.php"><strong>Sign Up</strong></a> to create your own chat room.
                            </p>
                            <a href="login.php" class="btn btn-primary">Member Login</a>
                        <?php endif; ?>
                    </div>
                </section>

                <!-- Chat Statistics -->
                <section class="box">
                    <div class="box-title">Room Statistics</div>
                    <div class="box-body">
                        <ul class="stats-list">
                            <li><span>Total Rooms</span><strong><?= $totalRooms ?></strong></li>
                            <li><span>Chatting Now</span><strong><?= $totalChatters ?></strong></li>
                            <li><span>Total Messages</span><strong><?= $totalMessages ?></strong></li>
                        </ul>
                    </div>
                </section>

                <!-- Quick Rules Reminder -->
                <section class="box">
                    <div class="box-title">Chat Etiquette</div>
                    <div class="box-body" style="color: var(--text-muted);">
                        <div>&bull; Respect all members in public rooms.</div>
                        <div>&bull; No flooding, spam, or bot scripts.</div>
                        <div>&bull; Offensive room names will be deleted.</div>
                        <div style="margin-top: 6px;"><a href="rules.php">Read full rules &raquo;</a></div>
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

    <!-- Custom Confirm Modal for Room Deletion -->
    <div class="modal-overlay" id="deleteRoomModal" role="dialog" aria-modal="true" aria-labelledby="deleteRoomTitle">
        <div class="modal-box">
            <div class="box-title">
                <span id="deleteRoomTitle">Delete Chat Room</span>
                <button type="button" class="btn btn-xs" onclick="closeDeleteModal()">&times;</button>
            </div>
            <div class="modal-body">
                <p style="margin-top: 0;">
                    Are you sure you want to permanently delete <strong id="deleteRoomName"></strong> and all of its messages?
                </p>
                <form action="rooms.php" method="post" style="margin: 0;">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="room_action" value="delete_room">
                    <input type="hidden" name="room_id" id="deleteRoomId" value="">
                    <div class="modal-actions">
                        <button type="button" class="btn btn-xs" onclick="closeDeleteModal()">Cancel</button>
                        <button type="submit" class="btn btn-danger btn-xs">Delete Room</button>
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

        /* Delete Room Modal */
        const deleteModal = document.getElementById('deleteRoomModal');
        function openDeleteRoomModal(roomId, roomName) {
            document.getElementById('deleteRoomId').value = roomId;
            document.getElementById('deleteRoomName').textContent = roomName;
            deleteModal.classList.add('open');
        }
        function closeDeleteModal() {
            deleteModal.classList.remove('open');
        }
        deleteModal.addEventListener('click', (e) => {
            if (e.target === deleteModal) closeDeleteModal();
        });
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') closeDeleteModal();
        });
    </script>

</body>
</html>