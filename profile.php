<?php
session_start();
require_once __DIR__ . '/db.php';

// Generate CSRF token for wall comments
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$isLoggedIn = !empty($_SESSION['user_id']);
$viewerId   = $isLoggedIn ? (int) $_SESSION['user_id'] : 0;
$viewerRole = 'user';

/**
 * Renders role badges (Admin blue tick, VIP/Mod tag) and Official Account tag for "dusknet".
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
 * Validates a hex color string (#rrggbb) before injecting into inline CSS.
 */
function safeHex(?string $color, string $default): string
{
    $color = trim((string) $color);
    return preg_match('/^#[0-9a-fA-F]{6}$/', $color) ? strtolower($color) : $default;
}

/**
 * Validates that a music URL is either a local /music/ upload or an http(s) URL.
 */
function safeMusicUrl(?string $url): string
{
    $url = trim((string) $url);
    if ($url === '') {
        return '';
    }
    if (str_starts_with($url, 'music/') && !str_contains($url, '..') && is_file(__DIR__ . '/' . $url)) {
        return $url;
    }
    if (preg_match('#^https?://#i', $url) && filter_var($url, FILTER_VALIDATE_URL)) {
        return $url;
    }
    return '';
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
 * Normalizes text to detect leetspeak (0->o, 1->i, 3->e, 5->s) and repeated letters.
 */
function normalizeTextForFilter(string $text): string
{
    $text = mb_strtolower($text, 'UTF-8');
    $map = [
        '0' => 'o', '1' => 'i', '3' => 'e', '4' => 'a',
        '5' => 's', '7' => 't', '8' => 'b', '9' => 'g',
        '$' => 's', '@' => 'a',
        'ı' => 'i', 'İ' => 'i', 'ş' => 's', 'ğ' => 'g',
        'ü' => 'u', 'ö' => 'o', 'ç' => 'c',
    ];
    $text = strtr($text, $map);
    $lettersOnly = preg_replace('/[^a-z\s]/', ' ', $text);
    $collapsed   = preg_replace('/(.)\1+/', '$1', $lettersOnly);
    return trim(preg_replace('/\s+/', ' ', $collapsed));
}

/**
 * Checks whether a wall comment contains profanity, slurs, or offensive language.
 */
function containsProfanity(string $text): bool
{
    $rawLower = mb_strtolower($text, 'UTF-8');
    $norm     = normalizeTextForFilter($text);
    $compact  = str_replace(' ', '', $norm);

    $blockedSubstrings = [
        // English
        'nigger', 'nigga', 'faggot', 'retard', 'chink', 'kike', 'spic',
        'fuck', 'fck', 'shit', 'bitch', 'cunt', 'whore', 'slut', 'bastard',
        'dickhead', 'asshole', 'motherfucker', 'cocksucker', 'blowjob', 'rape',
        // Turkish
        'siktir', 'sikim', 'sikeyim', 'sikerim', 'sikik', 'orospu', 'amcik',
        'yarrak', 'yarak', 'gavat', 'pezevenk', 'kahpe', 'gotveren', 'ibne',
        'ananisik', 'ananiski', 'amq', 'amk',
        // ES / PT / FR / DE / IT / RU
        'mierda', 'pendejo', 'cabron', 'maricon', 'caralho', 'porra', 'filhodaputa',
        'putain', 'salope', 'connard', 'encule', 'merde',
        'scheisse', 'arschloch', 'hurensohn', 'fotze', 'wichser',
        'vaffanculo', 'stronzo', 'troia', 'cazzo',
        'blyat', 'suka', 'cyka', 'pizdec', 'nahui', 'ebat', 'pidor',
    ];

    // Short terms checked as standalone words to prevent false positives (e.g. "pic" in "picture")
    $wordBoundaryOnly = ['aq', 'oc', 'oç', 'pic', 'piç', 'fag', 'fuk', 'puta', 'sik', 'got', 'göt'];

    foreach ($blockedSubstrings as $term) {
        $normTerm = str_replace(' ', '', normalizeTextForFilter($term));
        if (str_contains($compact, $normTerm) || str_contains($rawLower, $term)) {
            return true;
        }
    }

    $words = preg_split('/\s+/', $norm) ?: [];
    foreach ($wordBoundaryOnly as $shortTerm) {
        $normShort = normalizeTextForFilter($shortTerm);
        if (in_array($normShort, $words, true) || preg_match('/\b' . preg_quote($shortTerm, '/') . '\b/u', $rawLower)) {
            return true;
        }
    }

    return false;
}

$wallError        = '';
$wallSuccess      = '';
$friendError      = '';
$friendSuccess    = '';
$profileUser      = null;
$wallPosts        = [];
$pendingWallPosts = [];
$viewerWallPost   = null;
$friendsList      = [];
$friendsCount     = 0;
$incomingRequests = [];
$friendshipStatus = 'none'; // none | sent | received | friends

try {
    // Purge expired 24-hour temporary accounts
    try {
        $pdo->exec('DELETE FROM users WHERE expires_at IS NOT NULL AND expires_at <= NOW()');
    } catch (Throwable $e) {
        // Ignore if expires_at column doesn't exist yet
    }

    // Ensure profile_wall table exists for classic profile comments
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS profile_wall (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            profile_user_id INT UNSIGNED NOT NULL,
            author_user_id INT UNSIGNED NOT NULL,
            message VARCHAR(300) NOT NULL,
            status ENUM('pending', 'approved') NOT NULL DEFAULT 'pending',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_profile_user (profile_user_id),
            INDEX idx_wall_author (profile_user_id, author_user_id),
            CONSTRAINT fk_wall_profile FOREIGN KEY (profile_user_id) REFERENCES users(id) ON DELETE CASCADE,
            CONSTRAINT fk_wall_author FOREIGN KEY (author_user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    // Ensure 'status' column exists on existing profile_wall tables
    $wallStatusCol = $pdo->query("SHOW COLUMNS FROM profile_wall LIKE 'status'")->fetch();
    if (!$wallStatusCol) {
        $pdo->exec("ALTER TABLE profile_wall ADD COLUMN status ENUM('pending', 'approved') NOT NULL DEFAULT 'approved' AFTER message");
    }

    // Ensure friendships table exists for Add Friend / Requests
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

    // Sync logged-in viewer's activity and role
    if ($isLoggedIn) {
        $touch = $pdo->prepare('UPDATE users SET last_seen = NOW() WHERE id = ?');
        $touch->execute([$viewerId]);

        $meStmt = $pdo->prepare('SELECT username, role FROM users WHERE id = ? LIMIT 1');
        $meStmt->execute([$viewerId]);
        $me = $meStmt->fetch();
        if ($me) {
            $_SESSION['username'] = $me['username'];
            $_SESSION['role']     = $me['role'];
            $viewerRole           = strtolower(trim($me['role']));
        }
    }

    // Determine which username to load (?u=username, or own profile if omitted while logged in)
    $targetUsername = trim($_GET['u'] ?? '');
    if ($targetUsername === '' && $isLoggedIn) {
        $targetUsername = $_SESSION['username'];
    }

    if ($targetUsername !== '') {
        $stmt = $pdo->prepare('SELECT * FROM users WHERE username = ? LIMIT 1');
        $stmt->execute([$targetUsername]);
        $profileUser = $stmt->fetch() ?: null;
    }

    $profileId    = $profileUser ? (int) $profileUser['id'] : 0;
    $isOwnerCheck = $profileUser && $isLoggedIn && ($viewerId === $profileId);

    // Handle Friend & Profile Wall POST actions
    if ($profileUser && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $token  = $_POST['csrf_token'] ?? '';
        $action = $_POST['action'] ?? '';

        if (!$isLoggedIn) {
            $wallError = 'You must be logged in to perform this action.';
        } elseif (!hash_equals($_SESSION['csrf_token'], $token)) {
            $wallError = 'Security check failed. Please refresh and try again.';
        }

        // --- FRIEND ACTIONS ---
        elseif ($action === 'send_friend_request') {
            if ($viewerId === $profileId) {
                $friendError = 'You cannot add yourself as a friend.';
            } else {
                $existing = $pdo->prepare(
                    'SELECT id, requester_id, addressee_id, status FROM friendships
                     WHERE (requester_id = ? AND addressee_id = ?) OR (requester_id = ? AND addressee_id = ?) LIMIT 1'
                );
                $existing->execute([$viewerId, $profileId, $profileId, $viewerId]);
                $rel = $existing->fetch();

                if ($rel) {
                    if ($rel['status'] === 'accepted') {
                        $friendSuccess = 'You are already friends.';
                    } elseif ((int) $rel['requester_id'] === $profileId) {
                        // They already sent us a request -> auto-accept!
                        $pdo->prepare("UPDATE friendships SET status = 'accepted' WHERE id = ?")->execute([$rel['id']]);
                        $friendSuccess = 'Friend request accepted! You are now friends.';
                    } else {
                        $friendSuccess = 'Friend request is already pending.';
                    }
                } else {
                    $pdo->prepare("INSERT INTO friendships (requester_id, addressee_id, status) VALUES (?, ?, 'pending')")
                        ->execute([$viewerId, $profileId]);
                    $friendSuccess = 'Friend request sent to ' . $profileUser['username'] . '.';
                }
            }
        } elseif ($action === 'cancel_friend_request') {
            $pdo->prepare("DELETE FROM friendships WHERE requester_id = ? AND addressee_id = ? AND status = 'pending'")
                ->execute([$viewerId, $profileId]);
            $friendSuccess = 'Friend request cancelled.';
        } elseif ($action === 'accept_friend_request') {
            $reqUserId = (int) ($_POST['requester_id'] ?? $profileId);
            $upd = $pdo->prepare(
                "UPDATE friendships SET status = 'accepted' WHERE requester_id = ? AND addressee_id = ? AND status = 'pending'"
            );
            $upd->execute([$reqUserId, $viewerId]);
            if ($upd->rowCount() > 0) {
                $friendSuccess = 'Friend request accepted!';
            }
        } elseif ($action === 'decline_friend_request') {
            $reqUserId = (int) ($_POST['requester_id'] ?? $profileId);
            $pdo->prepare("DELETE FROM friendships WHERE requester_id = ? AND addressee_id = ? AND status = 'pending'")
                ->execute([$reqUserId, $viewerId]);
            $friendSuccess = 'Friend request declined.';
        } elseif ($action === 'remove_friend') {
            $targetFriendId = (int) ($_POST['friend_id'] ?? $profileId);
            $pdo->prepare(
                "DELETE FROM friendships WHERE status = 'accepted' AND ((requester_id = ? AND addressee_id = ?) OR (requester_id = ? AND addressee_id = ?))"
            )->execute([$viewerId, $targetFriendId, $targetFriendId, $viewerId]);
            $friendSuccess = 'Removed from friends list.';
        }

        // --- PROFILE WALL ACTIONS ---
        elseif ($action === 'add_wall_post') {
            $msg = trim($_POST['message'] ?? '');

            // Enforce 1 wall comment per user on this profile (profile owner can also only post once or delete & repost)
            $dupCheck = $pdo->prepare(
                'SELECT id FROM profile_wall WHERE profile_user_id = ? AND author_user_id = ? LIMIT 1'
            );
            $dupCheck->execute([$profileId, $viewerId]);

            if ($dupCheck->fetch()) {
                $wallError = 'You can only leave 1 comment on this profile. Delete your existing comment first if you want to post a new one.';
            } elseif ($msg === '') {
                $wallError = 'Please write a message before posting.';
            } elseif (mb_strlen($msg) > 300) {
                $wallError = 'Wall messages must be 300 characters or fewer.';
            } elseif (containsProfanity($msg)) {
                $wallError = 'Your message contains inappropriate or profane language and was blocked.';
            } else {
                // Auto-approve if the profile owner is posting on their own wall; otherwise require approval
                $initialStatus = $isOwnerCheck ? 'approved' : 'pending';
                $ins = $pdo->prepare(
                    'INSERT INTO profile_wall (profile_user_id, author_user_id, message, status) VALUES (?, ?, ?, ?)'
                );
                $ins->execute([$profileId, $viewerId, $msg, $initialStatus]);
                $wallSuccess = $isOwnerCheck
                    ? 'Message posted to your wall.'
                    : 'Comment submitted! It will appear publicly once ' . $profileUser['username'] . ' approves it.';
            }
        } elseif ($action === 'approve_wall_post') {
            $postId = (int) ($_POST['post_id'] ?? 0);
            if ($postId > 0 && ($isOwnerCheck || in_array($viewerRole, ['admin', 'mod'], true))) {
                $upd = $pdo->prepare(
                    "UPDATE profile_wall SET status = 'approved' WHERE id = ? AND profile_user_id = ? AND status = 'pending'"
                );
                $upd->execute([$postId, $profileId]);
                if ($upd->rowCount() > 0) {
                    $wallSuccess = 'Wall comment approved and is now visible on your profile.';
                }
            } else {
                $wallError = 'Only the profile owner can approve wall comments.';
            }
        } elseif ($action === 'delete_wall_post') {
            $postId = (int) ($_POST['post_id'] ?? 0);
            if ($postId > 0) {
                // Allow deletion by profile owner, comment author, mod, or admin
                $checkPost = $pdo->prepare(
                    'SELECT id, profile_user_id, author_user_id FROM profile_wall WHERE id = ? AND profile_user_id = ? LIMIT 1'
                );
                $checkPost->execute([$postId, $profileId]);
                $postRow = $checkPost->fetch();

                if ($postRow) {
                    $canDelete = (
                        $viewerId === (int) $postRow['profile_user_id'] ||
                        $viewerId === (int) $postRow['author_user_id'] ||
                        in_array($viewerRole, ['admin', 'mod'], true)
                    );
                    if ($canDelete) {
                        $del = $pdo->prepare('DELETE FROM profile_wall WHERE id = ?');
                        $del->execute([$postId]);
                        $wallSuccess = 'Wall comment removed.';
                    } else {
                        $wallError = 'You do not have permission to remove that comment.';
                    }
                }
            }
        }
    }

    // Load Friends & Wall data for this profile
    if ($profileUser) {
        // 1. Friendship status between viewer and profile owner
        if ($isLoggedIn && !$isOwnerCheck) {
            $fStmt = $pdo->prepare(
                'SELECT requester_id, addressee_id, status FROM friendships
                 WHERE (requester_id = ? AND addressee_id = ?) OR (requester_id = ? AND addressee_id = ?) LIMIT 1'
            );
            $fStmt->execute([$viewerId, $profileId, $profileId, $viewerId]);
            $fRow = $fStmt->fetch();
            if ($fRow) {
                if ($fRow['status'] === 'accepted') {
                    $friendshipStatus = 'friends';
                } elseif ((int) $fRow['requester_id'] === $viewerId) {
                    $friendshipStatus = 'sent';
                } else {
                    $friendshipStatus = 'received';
                }
            }
        }

        // 2. Accepted friends list (latest 8) + total friend count
        $cntStmt = $pdo->prepare(
            "SELECT COUNT(*) FROM friendships WHERE status = 'accepted' AND (requester_id = ? OR addressee_id = ?)"
        );
        $cntStmt->execute([$profileId, $profileId]);
        $friendsCount = (int) $cntStmt->fetchColumn();

        $flStmt = $pdo->prepare(
            "SELECT u.id, u.username, u.role, u.avatar
             FROM friendships f
             JOIN users u ON u.id = IF(f.requester_id = ?, f.addressee_id, f.requester_id)
             WHERE f.status = 'accepted' AND (f.requester_id = ? OR f.addressee_id = ?)
             ORDER BY f.created_at DESC
             LIMIT 8"
        );
        $flStmt->execute([$profileId, $profileId, $profileId]);
        $friendsList = $flStmt->fetchAll();

        // 3. Incoming pending friend requests (only visible to profile owner)
        if ($isOwnerCheck) {
            $reqStmt = $pdo->prepare(
                "SELECT u.id, u.username, u.role, u.avatar, f.created_at
                 FROM friendships f
                 JOIN users u ON u.id = f.requester_id
                 WHERE f.addressee_id = ? AND f.status = 'pending'
                 ORDER BY f.created_at DESC"
            );
            $reqStmt->execute([$profileId]);
            $incomingRequests = $reqStmt->fetchAll();
        }

        // 4. Approved wall comments (publicly visible, with author avatars)
        $wStmt = $pdo->prepare(
            "SELECT w.id, w.author_user_id, w.message, w.status, w.created_at,
                    u.username AS author_name, u.role AS author_role, u.avatar AS author_avatar
             FROM profile_wall w
             JOIN users u ON u.id = w.author_user_id
             WHERE w.profile_user_id = ? AND w.status = 'approved'
             ORDER BY w.created_at DESC
             LIMIT 25"
        );
        $wStmt->execute([$profileId]);
        $wallPosts = $wStmt->fetchAll();

        // 5. Pending wall comments awaiting owner approval
        if ($isOwnerCheck || in_array($viewerRole, ['admin', 'mod'], true)) {
            $pwStmt = $pdo->prepare(
                "SELECT w.id, w.author_user_id, w.message, w.created_at,
                        u.username AS author_name, u.role AS author_role, u.avatar AS author_avatar
                 FROM profile_wall w
                 JOIN users u ON u.id = w.author_user_id
                 WHERE w.profile_user_id = ? AND w.status = 'pending'
                 ORDER BY w.created_at ASC"
            );
            $pwStmt->execute([$profileId]);
            $pendingWallPosts = $pwStmt->fetchAll();
        }

        // 6. Check if current viewer already left a comment on this profile
        if ($isLoggedIn) {
            $vwStmt = $pdo->prepare(
                'SELECT id, message, status, created_at FROM profile_wall
                 WHERE profile_user_id = ? AND author_user_id = ? LIMIT 1'
            );
            $vwStmt->execute([$profileId, $viewerId]);
            $viewerWallPost = $vwStmt->fetch() ?: null;
        }
    }
} catch (Throwable $e) {
    $wallError = 'Unable to load profile data right now.';
}

// Prepare profile display properties & custom colors
$isOwnProfile = $profileUser && $isLoggedIn && ($viewerId === (int) $profileUser['id']);
$profileRole  = $profileUser ? strtolower(trim($profileUser['role'] ?? 'user')) : 'user';
$isOnline     = false;
if ($profileUser && !empty($profileUser['last_seen'])) {
    $isOnline = (time() - strtotime($profileUser['last_seen'])) <= 300;
}

$avatarSrc = ($profileUser && !empty($profileUser['avatar']) && !str_contains($profileUser['avatar'], '..') && is_file(__DIR__ . '/' . $profileUser['avatar']))
    ? $profileUser['avatar']
    : '';

$musicUrl   = $profileUser ? safeMusicUrl($profileUser['profile_music_url'] ?? '') : '';
$musicTitle = $profileUser ? trim((string) ($profileUser['profile_music_title'] ?? '')) : '';
if ($musicUrl !== '' && $musicTitle === '') {
    $musicTitle = basename(parse_url($musicUrl, PHP_URL_PATH) ?: 'Profile Track');
}

// Custom colors (with classic DuskNet defaults)
$customBg          = $profileUser ? safeHex($profileUser['profile_bg_color'] ?? null, '#d0d7e1') : '#d0d7e1';
$customText        = $profileUser ? safeHex($profileUser['profile_text_color'] ?? null, '#1a1a1a') : '#1a1a1a';
$customHeader      = $profileUser ? safeHex($profileUser['card_header_color'] ?? null, '#3b6a99') : '#3b6a99';
$customCardProfile = $profileUser ? safeHex($profileUser['card_profile_color'] ?? null, '#ffffff') : '#ffffff';
$customCardBio     = $profileUser ? safeHex($profileUser['card_bio_color'] ?? null, '#ffffff') : '#ffffff';
$customCardMusic   = $profileUser ? safeHex($profileUser['card_music_color'] ?? null, '#ffffff') : '#ffffff';
$customCardExtra   = $profileUser ? safeHex($profileUser['card_extra_color'] ?? null, '#ffffff') : '#ffffff';

$pageTitle = $profileUser
    ? htmlspecialchars($profileUser['username'], ENT_QUOTES, 'UTF-8') . "'s Profile - DuskNet"
    : 'Profile Not Found - DuskNet';
?>
<!DOCTYPE html>
<html lang="en" data-theme="blue">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?></title>
    <style>
        /* Theme Variables - Default: Classic 2003 Blue */
        :root,
        [data-theme="blue"] {
            --bg-page: #d0d7e1;
            --bg-pattern: rgba(255, 255, 255, 0.25);
            --bg-wrapper: #f7f9fc;
            --bg-box: #ffffff;
            --bg-soft: #e6ecf4;
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

        <?php if ($profileUser): ?>
        /* Apply user's custom profile background when custom style is active */
        body.custom-profile-on {
            background-color: <?= $customBg ?>;
        }

        body.custom-profile-on .profile-area {
            background-color: <?= $customBg ?>;
            color: <?= $customText ?>;
        }

        body.custom-profile-on .profile-area .box-title {
            background: <?= $customHeader ?>;
            background-image: linear-gradient(to bottom, rgba(255, 255, 255, 0.14), rgba(0, 0, 0, 0.14));
            border-bottom-color: rgba(0, 0, 0, 0.25);
        }

        body.custom-profile-on .card-profile { background-color: <?= $customCardProfile ?>; color: <?= $customText ?>; }
        body.custom-profile-on .card-bio     { background-color: <?= $customCardBio ?>; color: <?= $customText ?>; }
        body.custom-profile-on .card-music   { background-color: <?= $customCardMusic ?>; color: <?= $customText ?>; }
        body.custom-profile-on .card-extra   { background-color: <?= $customCardExtra ?>; color: <?= $customText ?>; }
        <?php endif; ?>

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

        /* Profile Toolbar (Custom Style Toggle + Music Status) */
        .profile-toolbar {
            background-color: var(--bg-soft);
            border-bottom: 1px solid var(--border);
            padding: 4px 12px;
            display: flex;
            flex-wrap: wrap;
            justify-content: space-between;
            align-items: center;
            gap: 6px;
            font-size: 10px;
            color: var(--text-muted);
        }

        /* Two-Column Profile Area */
        .profile-area {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            padding: 12px;
        }

        .left-col  { flex: 0 1 255px; min-width: 230px; margin: 0 auto; }
        .right-col { flex: 1 1 430px; min-width: 0; }

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
            gap: 6px;
        }

        .box-body { padding: 10px 11px; }

        /* Avatar & Identity */
        .avatar-wrap {
            width: 132px;
            height: 132px;
            margin: 0 auto 9px;
            border: 1px solid var(--border-strong);
            background-color: var(--bg-soft);
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            font-size: 40px;
            font-weight: bold;
            color: var(--text-muted);
        }

        .avatar-wrap img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }

        .profile-name-block {
            text-align: center;
            margin-bottom: 8px;
        }

        .profile-username {
            font-size: 14px;
            font-weight: bold;
            word-break: break-word;
        }

        .status-quote {
            margin: 6px 0 8px;
            padding: 5px 7px;
            border: 1px dotted var(--border-strong);
            background-color: rgba(128, 128, 128, 0.08);
            font-style: italic;
            font-size: 11px;
            word-break: break-word;
        }

        .online-dot {
            display: inline-block;
            width: 8px;
            height: 8px;
            border-radius: 50%;
            margin-right: 4px;
            vertical-align: middle;
        }
        .online-dot.on  { background-color: #27a844; border: 1px solid #166e2a; }
        .online-dot.off { background-color: #9aa3ad; border: 1px solid #6b737d; }

        /* Info List */
        .info-list { list-style: none; margin: 0; padding: 0; font-size: 11px; }
        .info-list li {
            display: flex;
            justify-content: space-between;
            gap: 8px;
            padding: 4px 0;
            border-bottom: 1px dotted rgba(128, 128, 128, 0.35);
        }
        .info-list li:last-child { border-bottom: none; }
        .info-label { opacity: 0.78; flex-shrink: 0; }
        .info-value { text-align: right; word-break: break-word; }

        /* Badges */
        .role-admin { color: var(--role-admin); font-weight: bold; }
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

        /* Bio & Music */
        .bio-content {
            white-space: pre-wrap;
            word-break: break-word;
            line-height: 1.55;
        }

        .details-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
            font-size: 11px;
        }

        .details-table th,
        .details-table td {
            padding: 6px 8px;
            border: 1px solid rgba(128, 128, 128, 0.35);
            vertical-align: top;
            text-align: left;
            word-break: break-word;
        }

        .details-table th {
            width: 34%;
            background-color: rgba(128, 128, 128, 0.12);
            font-weight: bold;
        }

        .details-table td {
            white-space: pre-wrap;
        }

        .empty-note {
            opacity: 0.72;
            font-style: italic;
        }

        .music-title {
            font-weight: bold;
            margin-bottom: 5px;
            word-break: break-word;
        }

        .music-controls {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 6px;
            margin-top: 6px;
        }

        .music-autoplay-hint {
            margin-top: 6px;
            padding: 4px 7px;
            font-size: 10px;
            border: 1px solid var(--border-strong);
            background-color: rgba(128, 128, 128, 0.1);
            display: none;
        }

        /* Wall / Guestbook */
        .wall-form textarea {
            width: 100%;
            min-height: 54px;
            padding: 4px 6px;
            border: 1px solid var(--input-border);
            background-color: var(--input-bg);
            color: var(--text);
            font-family: inherit;
            font-size: 11px;
            resize: vertical;
        }

        .wall-form textarea:focus { outline: none; border-color: var(--header-top); }

        .wall-list { margin-top: 10px; }

        .wall-item {
            display: flex;
            gap: 8px;
            align-items: flex-start;
            padding: 7px 0;
            border-top: 1px dotted rgba(128, 128, 128, 0.35);
        }

        .wall-avatar {
            width: 32px;
            height: 32px;
            border: 1px solid var(--border-strong);
            background-color: var(--bg-soft);
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            flex-shrink: 0;
            font-size: 12px;
            font-weight: bold;
            color: var(--text-muted);
            text-decoration: none;
        }

        .wall-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }

        .wall-body { flex: 1; min-width: 0; }

        .wall-item-head {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 6px;
            font-size: 10px;
            margin-bottom: 3px;
        }

        .wall-item-date { opacity: 0.7; }
        .wall-item-msg  { word-break: break-word; white-space: pre-wrap; }

        .wall-del-btn {
            background: none;
            border: none;
            color: var(--role-admin);
            font-family: inherit;
            font-size: 10px;
            cursor: pointer;
            padding: 0;
            margin-left: 6px;
        }
        .wall-del-btn:hover { text-decoration: underline; }

        /* Pending Approval & Friend Request Strips */
        .pending-box {
            border: 1px solid #d4a638;
            background-color: rgba(255, 244, 214, 0.55);
            padding: 7px 8px;
            margin-bottom: 10px;
        }

        .pending-title {
            font-weight: bold;
            font-size: 10px;
            margin-bottom: 5px;
            color: #7a4f00;
        }

        .pending-badge {
            display: inline-block;
            padding: 0 4px;
            font-size: 9px;
            font-weight: bold;
            background-color: #fff4d6;
            color: #8a5a00;
            border: 1px solid #d4a638;
            margin-left: 4px;
        }

        /* Friends Actions & Mini Grid */
        .friend-actions {
            margin-top: 9px;
            padding-top: 8px;
            border-top: 1px dotted rgba(128, 128, 128, 0.35);
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            gap: 5px;
        }

        .friends-mini-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 6px;
        }

        .friend-tile {
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
            text-decoration: none;
            color: inherit;
        }

        .friend-tile:hover { text-decoration: none; }

        .friend-tile-avatar {
            width: 44px;
            height: 44px;
            border: 1px solid var(--border-strong);
            background-color: var(--bg-soft);
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            font-size: 14px;
            font-weight: bold;
            color: var(--text-muted);
            margin-bottom: 3px;
        }

        .friend-tile-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }

        .friend-tile-name {
            font-size: 9px;
            font-weight: bold;
            color: var(--link);
            max-width: 100%;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .friend-tile:hover .friend-tile-name { text-decoration: underline; }

        .req-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 6px;
            padding: 4px 0;
            border-top: 1px dotted rgba(128, 128, 128, 0.35);
        }

        .req-row:first-of-type { border-top: none; }

        .btn-approve {
            background: linear-gradient(to bottom, #489957, #2e6e3a);
            color: #ffffff;
            border-color: #1f4f28;
        }

        .btn-danger {
            background: linear-gradient(to bottom, #c94b4b, #992d2d);
            color: #ffffff;
            border-color: #701d1d;
        }

        /* Status Messages */
        .msg-box {
            padding: 6px 8px;
            margin-bottom: 8px;
            font-size: 11px;
            border: 1px solid;
        }
        .msg-error { background-color: var(--err-bg); border-color: var(--err-border); color: var(--err-text); }
        .msg-success { background-color: var(--ok-bg); border-color: var(--ok-border); color: var(--ok-text); }

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
        }
        .btn:hover { text-decoration: none; filter: brightness(0.97); }
        .btn-primary {
            background: linear-gradient(to bottom, var(--btn-primary-top), var(--btn-primary-bot));
            color: #ffffff;
            border-color: var(--btn-primary-border);
            box-shadow: inset 1px 1px 0 rgba(255, 255, 255, 0.25);
        }
        .btn-xs {
            padding: 1px 6px;
            font-size: 10px;
            font-weight: normal;
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
<body class="<?= $profileUser ? 'custom-profile-on' : '' ?>">

    <div class="wrapper">

        <!-- Header -->
        <header class="header">
            <div>
                <a href="index.php" class="brand" style="text-decoration: none;">DuskNet</a>
                <div class="tagline">Public web chat rooms</div>
            </div>
            <form action="index.php" method="get" class="header-search">
                <input type="text" name="q" placeholder="Search users or rooms..." aria-label="Search users or rooms">
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

        <?php if (!$profileUser): ?>
            <!-- User Not Found View -->
            <main style="padding: 16px;">
                <section class="box">
                    <div class="box-title">Profile Not Found</div>
                    <div class="box-body">
                        <p style="margin-top: 0;">
                            The member profile you are looking for does not exist or may have expired.
                        </p>
                        <a href="index.php" class="btn btn-primary">Back to Home</a>
                    </div>
                </section>
            </main>
        <?php else: ?>
            <!-- Profile Toolbar -->
            <div class="profile-toolbar">
                <div>
                    Viewing profile of <strong><?= htmlspecialchars($profileUser['username'], ENT_QUOTES, 'UTF-8') ?></strong>
                    <?php if ($isOwnProfile): ?>
                        &bull; <a href="account.php?tab=customize"><strong>Edit My Profile</strong></a>
                    <?php endif; ?>
                </div>
                <div style="display: flex; align-items: center; gap: 6px;">
                    <button type="button" class="btn btn-xs" id="toggleCustomStyleBtn">Custom Colors: ON</button>
                    <button type="button" class="btn btn-xs" id="copyProfileLinkBtn">Copy Link</button>
                </div>
            </div>

            <!-- Customized Two-Column Profile Content -->
            <main class="profile-area">

                <!-- LEFT COLUMN: Profile Info Card + Music Player Card -->
                <aside class="left-col">

                    <!-- CARD 1: Profile Info Card -->
                    <section class="box card-profile">
                        <div class="box-title">
                            <span><?= htmlspecialchars($profileUser['username'], ENT_QUOTES, 'UTF-8') ?></span>
                            <span style="font-size: 10px; font-weight: normal;">
                                <?= $isOnline ? 'Online' : 'Offline' ?>
                            </span>
                        </div>
                        <div class="box-body">
                            <div class="avatar-wrap">
                                <?php if ($avatarSrc !== ''): ?>
                                    <img src="<?= htmlspecialchars($avatarSrc, ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($profileUser['username'], ENT_QUOTES, 'UTF-8') ?>'s avatar">
                                <?php else: ?>
                                    <?= htmlspecialchars(strtoupper(substr($profileUser['username'], 0, 1)), ENT_QUOTES, 'UTF-8') ?>
                                <?php endif; ?>
                            </div>

                            <div class="profile-name-block">
                                <div class="profile-username">
                                    <?= htmlspecialchars($profileUser['username'], ENT_QUOTES, 'UTF-8') ?><?= renderUserBadges($profileUser['username'], $profileUser['role']) ?>
                                </div>
                                <div style="margin-top: 3px; font-size: 10px;">
                                    <span class="online-dot <?= $isOnline ? 'on' : 'off' ?>"></span>
                                    <strong><?= $isOnline ? 'Online Now' : 'Offline' ?></strong>
                                </div>
                            </div>

                            <?php if (!empty($profileUser['status_text'])): ?>
                                <div class="status-quote">
                                    &ldquo;<?= htmlspecialchars($profileUser['status_text'], ENT_QUOTES, 'UTF-8') ?>&rdquo;
                                </div>
                            <?php endif; ?>

                            <ul class="info-list">
                                <li>
                                    <span class="info-label">Role</span>
                                    <span class="info-value">
                                        <?php if ($profileRole === 'admin'): ?>
                                            <strong class="role-admin">Admin</strong>
                                        <?php else: ?>
                                            <strong><?= htmlspecialchars($profileRole === 'vip' ? 'VIP' : ucfirst($profileRole), ENT_QUOTES, 'UTF-8') ?></strong>
                                        <?php endif; ?>
                                    </span>
                                </li>
                                <?php if (!empty($profileUser['age'])): ?>
                                    <li>
                                        <span class="info-label">Age</span>
                                        <span class="info-value"><strong><?= (int) $profileUser['age'] ?></strong></span>
                                    </li>
                                <?php endif; ?>
                                <li>
                                    <span class="info-label">Joined</span>
                                    <span class="info-value"><?= htmlspecialchars(date('Y-m-d', strtotime($profileUser['created_at'])), ENT_QUOTES, 'UTF-8') ?></span>
                                </li>
                                <li>
                                    <span class="info-label">Friends</span>
                                    <span class="info-value"><strong><?= $friendsCount ?></strong></span>
                                </li>
                                <li>
                                    <span class="info-label">Last Seen</span>
                                    <span class="info-value">
                                        <?php if ($isOnline): ?>
                                            Just now
                                        <?php elseif (!empty($profileUser['last_seen'])): ?>
                                            <?= htmlspecialchars(date('Y-m-d H:i', strtotime($profileUser['last_seen'])), ENT_QUOTES, 'UTF-8') ?>
                                        <?php else: ?>
                                            Never
                                        <?php endif; ?>
                                    </span>
                                </li>
                                <?php if (!empty($profileUser['expires_at'])): ?>
                                    <li>
                                        <span class="info-label">Account</span>
                                        <span class="info-value"><strong>24h Temporary</strong></span>
                                    </li>
                                <?php endif; ?>
                            </ul>

                            <?php if ($friendError !== ''): ?>
                                <div class="msg-box msg-error" style="margin-top: 8px; margin-bottom: 0;">
                                    <?= htmlspecialchars($friendError, ENT_QUOTES, 'UTF-8') ?>
                                </div>
                            <?php endif; ?>

                            <?php if ($friendSuccess !== ''): ?>
                                <div class="msg-box msg-success" style="margin-top: 8px; margin-bottom: 0;">
                                    <?= htmlspecialchars($friendSuccess, ENT_QUOTES, 'UTF-8') ?>
                                </div>
                            <?php endif; ?>

                            <?php if ($isOwnProfile): ?>
                                <div class="friend-actions">
                                    <a href="account.php?tab=profile" class="btn btn-xs">Change Avatar</a>
                                    <a href="account.php?tab=customize" class="btn btn-primary btn-xs">Edit Design</a>
                                </div>
                            <?php elseif ($isLoggedIn): ?>
                                <div class="friend-actions">
                                    <?php if ($friendshipStatus === 'none'): ?>
                                        <form action="profile.php?u=<?= urlencode($profileUser['username']) ?>" method="post" style="margin: 0;">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                                            <input type="hidden" name="action" value="send_friend_request">
                                            <button type="submit" class="btn btn-primary btn-xs">+ Add Friend</button>
                                        </form>
                                    <?php elseif ($friendshipStatus === 'sent'): ?>
                                        <form action="profile.php?u=<?= urlencode($profileUser['username']) ?>" method="post" style="margin: 0;">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                                            <input type="hidden" name="action" value="cancel_friend_request">
                                            <button type="submit" class="btn btn-xs">Request Sent (Cancel)</button>
                                        </form>
                                    <?php elseif ($friendshipStatus === 'received'): ?>
                                        <form action="profile.php?u=<?= urlencode($profileUser['username']) ?>" method="post" style="margin: 0;">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                                            <input type="hidden" name="action" value="accept_friend_request">
                                            <input type="hidden" name="requester_id" value="<?= (int) $profileUser['id'] ?>">
                                            <button type="submit" class="btn btn-approve btn-xs">&#10003; Accept Friend</button>
                                        </form>
                                        <form action="profile.php?u=<?= urlencode($profileUser['username']) ?>" method="post" style="margin: 0;">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                                            <input type="hidden" name="action" value="decline_friend_request">
                                            <input type="hidden" name="requester_id" value="<?= (int) $profileUser['id'] ?>">
                                            <button type="submit" class="btn btn-xs">Decline</button>
                                        </form>
                                    <?php elseif ($friendshipStatus === 'friends'): ?>
                                        <span class="btn btn-approve btn-xs" style="cursor: default;">&#10003; Friends</span>
                                        <form action="profile.php?u=<?= urlencode($profileUser['username']) ?>" method="post" style="margin: 0;">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                                            <input type="hidden" name="action" value="remove_friend">
                                            <input type="hidden" name="friend_id" value="<?= (int) $profileUser['id'] ?>">
                                            <button type="submit" class="btn btn-xs">Unfriend</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            <?php else: ?>
                                <div class="friend-actions">
                                    <a href="login.php" class="btn btn-xs">Login to Add Friend</a>
                                </div>
                            <?php endif; ?>
                        </div>
                    </section>

                    <!-- CARD 1B: Friends & Requests -->
                    <section class="box card-profile">
                        <div class="box-title">
                            <span>Friends (<?= $friendsCount ?>)</span>
                        </div>
                        <div class="box-body">
                            <?php if ($isOwnProfile && !empty($incomingRequests)): ?>
                                <div class="pending-box">
                                    <div class="pending-title">Friend Requests (<?= count($incomingRequests) ?>)</div>
                                    <?php foreach ($incomingRequests as $req): ?>
                                        <div class="req-row">
                                            <a href="profile.php?u=<?= urlencode($req['username']) ?>"><strong><?= htmlspecialchars($req['username'], ENT_QUOTES, 'UTF-8') ?></strong></a>
                                            <div style="display: flex; gap: 3px;">
                                                <form action="profile.php?u=<?= urlencode($profileUser['username']) ?>" method="post" style="margin: 0;">
                                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                                                    <input type="hidden" name="action" value="accept_friend_request">
                                                    <input type="hidden" name="requester_id" value="<?= (int) $req['id'] ?>">
                                                    <button type="submit" class="btn btn-approve btn-xs">Accept</button>
                                                </form>
                                                <form action="profile.php?u=<?= urlencode($profileUser['username']) ?>" method="post" style="margin: 0;">
                                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                                                    <input type="hidden" name="action" value="decline_friend_request">
                                                    <input type="hidden" name="requester_id" value="<?= (int) $req['id'] ?>">
                                                    <button type="submit" class="btn btn-xs">No</button>
                                                </form>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>

                            <?php if (!empty($friendsList)): ?>
                                <div class="friends-mini-grid">
                                    <?php foreach ($friendsList as $fr): ?>
                                        <?php $frAv = getSafeAvatar($fr['avatar'] ?? ''); ?>
                                        <a href="profile.php?u=<?= urlencode($fr['username']) ?>" class="friend-tile" title="<?= htmlspecialchars($fr['username'], ENT_QUOTES, 'UTF-8') ?>">
                                            <div class="friend-tile-avatar">
                                                <?php if ($frAv !== ''): ?>
                                                    <img src="<?= htmlspecialchars($frAv, ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($fr['username'], ENT_QUOTES, 'UTF-8') ?>">
                                                <?php else: ?>
                                                    <?= htmlspecialchars(strtoupper(substr($fr['username'], 0, 1)), ENT_QUOTES, 'UTF-8') ?>
                                                <?php endif; ?>
                                            </div>
                                            <div class="friend-tile-name"><?= htmlspecialchars($fr['username'], ENT_QUOTES, 'UTF-8') ?></div>
                                        </a>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <span class="empty-note">No friends added yet.</span>
                            <?php endif; ?>
                        </div>
                    </section>

                    <!-- CARD 2: Profile Music Player Card -->
                    <section class="box card-music">
                        <div class="box-title">
                            <span>&#9835; Profile Music</span>
                            <?php if ($musicUrl !== ''): ?>
                                <span id="musicStateLabel" style="font-size: 9px; font-weight: normal;">Ready</span>
                            <?php endif; ?>
                        </div>
                        <div class="box-body">
                            <?php if ($musicUrl !== ''): ?>
                                <div class="music-title">
                                    <?= htmlspecialchars($musicTitle, ENT_QUOTES, 'UTF-8') ?>
                                </div>
                                <audio id="profileAudio" controls loop preload="auto" style="width: 100%; height: 28px;">
                                    <source src="<?= htmlspecialchars($musicUrl, ENT_QUOTES, 'UTF-8') ?>">
                                    Your browser does not support audio playback.
                                </audio>
                                <div class="music-controls">
                                    <button type="button" class="btn btn-primary btn-xs" id="musicPlayToggle">&#9654; Play</button>
                                    <button type="button" class="btn btn-xs" id="musicMuteToggle">Mute</button>
                                </div>
                                <div class="music-autoplay-hint" id="autoplayHint">
                                    Browser blocked auto-play. Click <strong>Play</strong> above to listen.
                                </div>
                            <?php else: ?>
                                <span class="empty-note">No profile music set.</span>
                                <?php if ($isOwnProfile): ?>
                                    <div style="margin-top: 6px;">
                                        <a href="account.php?tab=customize">Add profile music &raquo;</a>
                                    </div>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </section>

                </aside>

                <!-- RIGHT COLUMN: About Me (Bio) Card + Details / Wall Card -->
                <div class="right-col">

                    <!-- CARD 3: About Me (Bio) Card -->
                    <section class="box card-bio">
                        <div class="box-title">
                            <span>About <?= htmlspecialchars($profileUser['username'], ENT_QUOTES, 'UTF-8') ?></span>
                        </div>
                        <div class="box-body">
                            <?php if (!empty($profileUser['bio'])): ?>
                                <div class="bio-content"><?= htmlspecialchars($profileUser['bio'], ENT_QUOTES, 'UTF-8') ?></div>
                            <?php else: ?>
                                <span class="empty-note">This user hasn't written a bio yet.</span>
                            <?php endif; ?>

                            <?php
                            $hasInterests = !empty($profileUser['interests']);
                            $hasArtists   = !empty($profileUser['favorite_artists']);
                            $hasMedia     = !empty($profileUser['favorite_media']);
                            $hasContacts  = !empty($profileUser['contacts']);
                            ?>
                            <?php if ($hasInterests || $hasArtists || $hasMedia || $hasContacts): ?>
                                <table class="details-table">
                                    <tbody>
                                        <?php if ($hasInterests): ?>
                                            <tr>
                                                <th>Interests</th>
                                                <td><?= htmlspecialchars($profileUser['interests'], ENT_QUOTES, 'UTF-8') ?></td>
                                            </tr>
                                        <?php endif; ?>
                                        <?php if ($hasArtists): ?>
                                            <tr>
                                                <th>Favorite Artists</th>
                                                <td><?= htmlspecialchars($profileUser['favorite_artists'], ENT_QUOTES, 'UTF-8') ?></td>
                                            </tr>
                                        <?php endif; ?>
                                        <?php if ($hasMedia): ?>
                                            <tr>
                                                <th>Series / Movies / Anime</th>
                                                <td><?= htmlspecialchars($profileUser['favorite_media'], ENT_QUOTES, 'UTF-8') ?></td>
                                            </tr>
                                        <?php endif; ?>
                                        <?php if ($hasContacts): ?>
                                            <tr>
                                                <th>Contacts</th>
                                                <td><?= htmlspecialchars($profileUser['contacts'], ENT_QUOTES, 'UTF-8') ?></td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            <?php endif; ?>
                        </div>
                    </section>

                    <!-- CARD 4: Details & Profile Wall Card -->
                    <section class="box card-extra">
                        <div class="box-title">
                            <span>Profile Wall (<?= count($wallPosts) ?>)</span>
                            <?php if (!empty($pendingWallPosts)): ?>
                                <span style="font-size: 9px; font-weight: normal;"><?= count($pendingWallPosts) ?> pending</span>
                            <?php endif; ?>
                        </div>
                        <div class="box-body">

                            <?php if ($wallError !== ''): ?>
                                <div class="msg-box msg-error">
                                    <?= htmlspecialchars($wallError, ENT_QUOTES, 'UTF-8') ?>
                                </div>
                            <?php endif; ?>

                            <?php if ($wallSuccess !== ''): ?>
                                <div class="msg-box msg-success">
                                    <?= htmlspecialchars($wallSuccess, ENT_QUOTES, 'UTF-8') ?>
                                </div>
                            <?php endif; ?>

                            <?php if (!empty($pendingWallPosts)): ?>
                                <!-- Owner/Staff Pending Approval Queue -->
                                <div class="pending-box">
                                    <div class="pending-title">Pending Wall Comments (Awaiting Your Approval)</div>
                                    <?php foreach ($pendingWallPosts as $pPost): ?>
                                        <?php $pAv = getSafeAvatar($pPost['author_avatar'] ?? ''); ?>
                                        <div class="wall-item" style="padding: 5px 0;">
                                            <a href="profile.php?u=<?= urlencode($pPost['author_name']) ?>" class="wall-avatar">
                                                <?php if ($pAv !== ''): ?>
                                                    <img src="<?= htmlspecialchars($pAv, ENT_QUOTES, 'UTF-8') ?>" alt="">
                                                <?php else: ?>
                                                    <?= htmlspecialchars(strtoupper(substr($pPost['author_name'], 0, 1)), ENT_QUOTES, 'UTF-8') ?>
                                                <?php endif; ?>
                                            </a>
                                            <div class="wall-body">
                                                <div class="wall-item-head">
                                                    <div>
                                                        <a href="profile.php?u=<?= urlencode($pPost['author_name']) ?>"><strong><?= htmlspecialchars($pPost['author_name'], ENT_QUOTES, 'UTF-8') ?></strong></a><?= renderUserBadges($pPost['author_name'], $pPost['author_role']) ?>
                                                        <span class="pending-badge">Pending</span>
                                                    </div>
                                                    <div style="display: flex; align-items: center; gap: 4px;">
                                                        <form action="profile.php?u=<?= urlencode($profileUser['username']) ?>" method="post" style="margin: 0;">
                                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                                                            <input type="hidden" name="action" value="approve_wall_post">
                                                            <input type="hidden" name="post_id" value="<?= (int) $pPost['id'] ?>">
                                                            <button type="submit" class="btn btn-approve btn-xs">Approve</button>
                                                        </form>
                                                        <form action="profile.php?u=<?= urlencode($profileUser['username']) ?>" method="post" style="margin: 0;">
                                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                                                            <input type="hidden" name="action" value="delete_wall_post">
                                                            <input type="hidden" name="post_id" value="<?= (int) $pPost['id'] ?>">
                                                            <button type="submit" class="btn btn-danger btn-xs">Reject</button>
                                                        </form>
                                                    </div>
                                                </div>
                                                <div class="wall-item-msg"><?= htmlspecialchars($pPost['message'], ENT_QUOTES, 'UTF-8') ?></div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>

                            <?php if ($isLoggedIn): ?>
                                <?php if ($viewerWallPost): ?>
                                    <div class="msg-box" style="background-color: rgba(128, 128, 128, 0.08); border-color: var(--border-strong);">
                                        <?php if ($viewerWallPost['status'] === 'pending'): ?>
                                            <div>
                                                <strong>Your comment is waiting for approval</strong> <span class="pending-badge">Pending</span>
                                            </div>
                                            <div style="margin: 4px 0; font-style: italic;">&ldquo;<?= htmlspecialchars($viewerWallPost['message'], ENT_QUOTES, 'UTF-8') ?>&rdquo;</div>
                                            <form action="profile.php?u=<?= urlencode($profileUser['username']) ?>" method="post" style="margin: 0;">
                                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                                                <input type="hidden" name="action" value="delete_wall_post">
                                                <input type="hidden" name="post_id" value="<?= (int) $viewerWallPost['id'] ?>">
                                                <button type="submit" class="btn btn-xs">Delete Pending Comment</button>
                                            </form>
                                        <?php else: ?>
                                            You have already posted a comment on this wall (limit: 1 comment per user). Delete your comment below if you want to write a new one.
                                        <?php endif; ?>
                                    </div>
                                <?php else: ?>
                                    <form action="profile.php?u=<?= urlencode($profileUser['username']) ?>" method="post" class="wall-form">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                                        <input type="hidden" name="action" value="add_wall_post">
                                        <textarea id="wallMessageInput" name="message" maxlength="300" placeholder="Leave a message on <?= htmlspecialchars($profileUser['username'], ENT_QUOTES, 'UTF-8') ?>'s wall... (1 comment per user, no profanity)" required></textarea>
                                        <div style="margin-top: 5px; display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 6px;">
                                            <span style="font-size: 10px; opacity: 0.75;">
                                                <span id="wallCharCount">0</span>/300 &bull; <?= $isOwnProfile ? 'Visible immediately' : 'Requires owner approval' ?>
                                            </span>
                                            <button type="submit" class="btn btn-primary btn-xs">Post Comment</button>
                                        </div>
                                    </form>
                                <?php endif; ?>
                            <?php else: ?>
                                <div class="empty-note" style="margin-bottom: 6px;">
                                    <a href="login.php">Login</a> to leave a comment on this profile wall.
                                </div>
                            <?php endif; ?>

                            <div class="wall-list">
                                <?php if (empty($wallPosts)): ?>
                                    <div class="wall-item">
                                        <span class="empty-note">No approved wall comments yet.</span>
                                    </div>
                                <?php else: ?>
                                    <?php foreach ($wallPosts as $post): ?>
                                        <?php
                                        $canDelPost = $isLoggedIn && (
                                            $viewerId === (int) $profileUser['id'] ||
                                            $viewerId === (int) $post['author_user_id'] ||
                                            in_array($viewerRole, ['admin', 'mod'], true)
                                        );
                                        $postAv = getSafeAvatar($post['author_avatar'] ?? '');
                                        ?>
                                        <div class="wall-item">
                                            <a href="profile.php?u=<?= urlencode($post['author_name']) ?>" class="wall-avatar">
                                                <?php if ($postAv !== ''): ?>
                                                    <img src="<?= htmlspecialchars($postAv, ENT_QUOTES, 'UTF-8') ?>" alt="">
                                                <?php else: ?>
                                                    <?= htmlspecialchars(strtoupper(substr($post['author_name'], 0, 1)), ENT_QUOTES, 'UTF-8') ?>
                                                <?php endif; ?>
                                            </a>
                                            <div class="wall-body">
                                                <div class="wall-item-head">
                                                    <div>
                                                        <a href="profile.php?u=<?= urlencode($post['author_name']) ?>"><strong><?= htmlspecialchars($post['author_name'], ENT_QUOTES, 'UTF-8') ?></strong></a><?= renderUserBadges($post['author_name'], $post['author_role']) ?>
                                                    </div>
                                                    <div>
                                                        <span class="wall-item-date"><?= htmlspecialchars(date('Y-m-d H:i', strtotime($post['created_at'])), ENT_QUOTES, 'UTF-8') ?></span>
                                                        <?php if ($canDelPost): ?>
                                                            <form action="profile.php?u=<?= urlencode($profileUser['username']) ?>" method="post" style="display: inline;">
                                                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                                                                <input type="hidden" name="action" value="delete_wall_post">
                                                                <input type="hidden" name="post_id" value="<?= (int) $post['id'] ?>">
                                                                <button type="submit" class="wall-del-btn">[delete]</button>
                                                            </form>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                                <div class="wall-item-msg"><?= htmlspecialchars($post['message'], ENT_QUOTES, 'UTF-8') ?></div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>

                        </div>
                    </section>

                </div>

            </main>
        <?php endif; ?>

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

        /* Toggle Custom Profile Colors ON/OFF */
        const styleBtn = document.getElementById('toggleCustomStyleBtn');
        if (styleBtn) {
            styleBtn.addEventListener('click', () => {
                const isOn = document.body.classList.toggle('custom-profile-on');
                styleBtn.textContent = isOn ? 'Custom Colors: ON' : 'Custom Colors: OFF';
            });
        }

        /* Copy Profile Link */
        const copyBtn = document.getElementById('copyProfileLinkBtn');
        if (copyBtn) {
            copyBtn.addEventListener('click', () => {
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(window.location.href).then(() => {
                        copyBtn.textContent = 'Copied!';
                        setTimeout(() => { copyBtn.textContent = 'Copy Link'; }, 1800);
                    }).catch(() => {});
                }
            });
        }

        /* Background Profile Music Player (Auto-play attempt + Play/Pause/Mute) */
        const audio = document.getElementById('profileAudio');
        const playBtn = document.getElementById('musicPlayToggle');
        const muteBtn = document.getElementById('musicMuteToggle');
        const stateLabel = document.getElementById('musicStateLabel');
        const autoplayHint = document.getElementById('autoplayHint');

        if (audio && playBtn && muteBtn) {
            audio.volume = 0.5;

            const syncMusicUI = () => {
                const playing = !audio.paused && !audio.ended;
                playBtn.innerHTML = playing ? '&#9646;&#9646; Pause' : '&#9654; Play';
                if (stateLabel) stateLabel.textContent = playing ? 'Playing' : 'Paused';
                muteBtn.textContent = audio.muted ? 'Unmute' : 'Mute';
                if (playing && autoplayHint) autoplayHint.style.display = 'none';
            };

            playBtn.addEventListener('click', () => {
                if (audio.paused) {
                    audio.play().then(syncMusicUI).catch(() => {});
                } else {
                    audio.pause();
                    syncMusicUI();
                }
            });

            muteBtn.addEventListener('click', () => {
                audio.muted = !audio.muted;
                syncMusicUI();
            });

            audio.addEventListener('play', syncMusicUI);
            audio.addEventListener('pause', syncMusicUI);
            audio.addEventListener('volumechange', syncMusicUI);

            // Try to auto-play background music when profile opens
            const playPromise = audio.play();
            if (playPromise !== undefined) {
                playPromise.then(syncMusicUI).catch(() => {
                    syncMusicUI();
                    if (autoplayHint) autoplayHint.style.display = 'block';
                });
            }
        }

        /* Live Wall Character Counter */
        const wallInput = document.getElementById('wallMessageInput');
        const wallCount = document.getElementById('wallCharCount');
        if (wallInput && wallCount) {
            wallInput.addEventListener('input', () => {
                wallCount.textContent = String(wallInput.value.length);
            });
        }
    </script>

</body>
</html>