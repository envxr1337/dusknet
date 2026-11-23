<?php
session_start();
require_once __DIR__ . '/db.php';

// Redirect guests to login page
if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

// Generate CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

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
 * Normalizes a username to catch leetspeak and repeated chars for policy checks.
 */
function normalizeForFilter(string $text): string
{
    $text = strtolower($text);
    $map = [
        '0' => 'o', '1' => 'i', '3' => 'e', '4' => 'a',
        '5' => 's', '7' => 't', '8' => 'b', '9' => 'g',
        '$' => 's', '@' => 'a',
    ];
    $text = strtr($text, $map);
    $lettersOnly = preg_replace('/[^a-z]/', '', $text);
    return preg_replace('/(.)\1+/', '$1', $lettersOnly);
}

/**
 * Validates new username against DuskNet impersonation, reserved words, and profanity.
 */
function checkUsernamePolicy(string $username): ?string
{
    $rawLower  = strtolower($username);
    $norm      = normalizeForFilter($username);
    $noSymbols = preg_replace('/[^a-z]/', '', $rawLower);

    if (str_contains($norm, 'dusknet') || str_contains($noSymbols, 'dusknet')) {
        return 'Usernames containing or resembling "DuskNet" are not allowed.';
    }

    $len = strlen($norm);
    foreach ([6, 7, 8] as $win) {
        if ($len >= $win) {
            for ($i = 0; $i <= $len - $win; $i++) {
                if (levenshtein(substr($norm, $i, $win), 'dusknet') <= 1) {
                    return 'Usernames similar to "DuskNet" are not allowed.';
                }
            }
        }
    }

    $reserved = ['admin', 'moderator', 'official', 'support', 'staff', 'sysadmin', 'webmaster'];
    foreach ($reserved as $word) {
        if (str_contains($norm, $word)) {
            return 'This username contains a reserved system word.';
        }
    }

    $blockedTerms = [
        'nigger', 'nigga', 'nigr', 'faggot', 'fag', 'retard', 'chink', 'kike', 'spic',
        'fuck', 'fuk', 'fck', 'shit', 'bitch', 'cunt', 'whore', 'slut', 'bastard',
        'dickhead', 'asshole', 'motherfucker', 'cocksucker', 'blowjob', 'porn', 'rape', 'nazi', 'hitler',
        'siktir', 'sikim', 'sikeyim', 'orospu', 'amcik', 'amk', 'aq', 'yarrak', 'yarak',
        'piç', 'pic', 'gavat', 'pezevenk', 'kahpe', 'gotveren', 'ibne', 'oc', 'oç', 'ananisik',
        'puta', 'mierda', 'pendejo', 'cabron', 'maricon', 'caralho', 'porra', 'filhodaputa',
        'putain', 'salope', 'connard', 'encule', 'merde',
        'scheisse', 'arschloch', 'hurensohn', 'fotze', 'wichser',
        'vaffanculo', 'stronzo', 'troia', 'cazzo',
        'blyat', 'suka', 'cyka', 'pizdec', 'nahui', 'ebat', 'pidor',
    ];
    $exactOnly = ['aq', 'oc', 'oç', 'pic', 'piç', 'fag'];

    foreach ($blockedTerms as $term) {
        $normTerm = normalizeForFilter($term);
        if (in_array($term, $exactOnly, true)) {
            if ($rawLower === $term || $norm === $normTerm) {
                return 'This username contains inappropriate or offensive language.';
            }
        } elseif (str_contains($norm, $normTerm) || str_contains($rawLower, $term)) {
            return 'This username contains inappropriate or offensive language.';
        }
    }

    return null;
}

/**
 * Validates a hex color string (#rrggbb) and falls back to default if invalid.
 */
function sanitizeHexColor(string $color, string $default): string
{
    $color = trim($color);
    return preg_match('/^#[0-9a-fA-F]{6}$/', $color) ? strtolower($color) : $default;
}

/**
 * Ensures new profile/avatar/customization columns and 'vip' role exist in users table.
 */
function ensureAccountSchema(PDO $pdo): void
{
    try {
        // Add 'vip' to role ENUM if missing
        $roleCol = $pdo->query("SHOW COLUMNS FROM users LIKE 'role'")->fetch();
        if ($roleCol && !str_contains(strtolower($roleCol['Type']), "'vip'")) {
            $pdo->exec("ALTER TABLE users MODIFY COLUMN role ENUM('user', 'vip', 'mod', 'admin') NOT NULL DEFAULT 'user'");
        }

        $columns = [
            'avatar'              => 'VARCHAR(255) NULL DEFAULT NULL',
            'username_changed_at' => 'TIMESTAMP NULL DEFAULT NULL',
            'bio'                 => 'TEXT NULL DEFAULT NULL',
            'status_text'         => 'VARCHAR(120) NULL DEFAULT NULL',
            'profile_bg_color'    => "VARCHAR(7) NOT NULL DEFAULT '#d0d7e1'",
            'profile_text_color'  => "VARCHAR(7) NOT NULL DEFAULT '#1a1a1a'",
            'card_header_color'   => "VARCHAR(7) NOT NULL DEFAULT '#3b6a99'",
            'card_profile_color'  => "VARCHAR(7) NOT NULL DEFAULT '#ffffff'",
            'card_bio_color'      => "VARCHAR(7) NOT NULL DEFAULT '#ffffff'",
            'card_music_color'    => "VARCHAR(7) NOT NULL DEFAULT '#ffffff'",
            'card_extra_color'    => "VARCHAR(7) NOT NULL DEFAULT '#ffffff'",
            'profile_music_title' => 'VARCHAR(100) NULL DEFAULT NULL',
            'profile_music_url'   => 'VARCHAR(255) NULL DEFAULT NULL',
            'age'                 => 'TINYINT UNSIGNED NULL DEFAULT NULL',
            'interests'           => 'TEXT NULL DEFAULT NULL',
            'favorite_artists'    => 'VARCHAR(255) NULL DEFAULT NULL',
            'favorite_media'      => 'VARCHAR(255) NULL DEFAULT NULL',
            'contacts'            => 'TEXT NULL DEFAULT NULL',
        ];

        $existing = [];
        foreach ($pdo->query('SHOW COLUMNS FROM users')->fetchAll() as $col) {
            $existing[$col['Field']] = true;
        }

        foreach ($columns as $name => $definition) {
            if (!isset($existing[$name])) {
                $pdo->exec("ALTER TABLE users ADD COLUMN `{$name}` {$definition}");
            }
        }
    } catch (Throwable $e) {
        // Ignore if DB user lacks ALTER permissions
    }
}

ensureAccountSchema($pdo);

$errors = [];
$success = '';
$activeTab = $_GET['tab'] ?? 'overview';
$allowedTabs = ['overview', 'profile', 'customize', 'security'];
if (!in_array($activeTab, $allowedTabs, true)) {
    $activeTab = 'overview';
}

$user = null;
$myFriends = [];
$myFriendsCount = 0;

try {
    // Update last_seen activity timestamp
    $touch = $pdo->prepare('UPDATE users SET last_seen = NOW() WHERE id = ?');
    $touch->execute([$_SESSION['user_id']]);

    // Fetch latest account details from DB
    $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();

    if (!$user) {
        header('Location: logout.php');
        exit;
    }

    // Keep session synced with DB
    $_SESSION['username'] = $user['username'];
    $_SESSION['role'] = $user['role'];

    $userRole = strtolower(trim($user['role'] ?? 'user'));
    $canUseGif = in_array($userRole, ['vip', 'mod', 'admin'], true);

    // Ensure friendships table exists & load accepted friends for Overview
    try {
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

        $frStmt = $pdo->prepare(
            "SELECT u.id, u.username, u.role, u.avatar
             FROM friendships f
             JOIN users u ON u.id = IF(f.requester_id = ?, f.addressee_id, f.requester_id)
             WHERE f.status = 'accepted' AND (f.requester_id = ? OR f.addressee_id = ?)
             ORDER BY f.created_at DESC"
        );
        $frStmt->execute([$user['id'], $user['id'], $user['id']]);
        $myFriends = $frStmt->fetchAll();
        $myFriendsCount = count($myFriends);
    } catch (Throwable $e) {
        // Keep account page functional even if friendships query fails
    }

    // Handle POST forms
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $token = $_POST['csrf_token'] ?? '';
        $action = $_POST['action'] ?? '';
        $postedTab = $_POST['tab'] ?? $activeTab;
        if (in_array($postedTab, $allowedTabs, true)) {
            $activeTab = $postedTab;
        }

        if (!hash_equals($_SESSION['csrf_token'], $token)) {
            $errors[] = 'Invalid form submission (CSRF token mismatch). Please refresh and try again.';
        }

        // 1. Upload Avatar (saved to /avatars/, GIF requires VIP/Mod/Admin)
        elseif ($action === 'upload_avatar') {
            $activeTab = 'profile';
            if (empty($_FILES['avatar']) || $_FILES['avatar']['error'] === UPLOAD_ERR_NO_FILE) {
                $errors[] = 'Please choose an image file to upload.';
            } elseif ($_FILES['avatar']['error'] !== UPLOAD_ERR_OK) {
                $errors[] = 'File upload failed. Please try a smaller image.';
            } elseif ($_FILES['avatar']['size'] > 3 * 1024 * 1024) {
                $errors[] = 'Avatar file size must be 3 MB or less.';
            } else {
                $tmpPath = $_FILES['avatar']['tmp_name'];
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $mime = $finfo->file($tmpPath);

                $allowedMimes = [
                    'image/jpeg' => 'jpg',
                    'image/png'  => 'png',
                    'image/webp' => 'webp',
                    'image/gif'  => 'gif',
                ];

                if (!isset($allowedMimes[$mime])) {
                    $errors[] = 'Only JPG, PNG, WEBP, or GIF images are supported.';
                } elseif ($mime === 'image/gif' && !$canUseGif) {
                    $errors[] = 'Animated GIF avatars require a VIP or Mod role.';
                } else {
                    $avatarDir = __DIR__ . '/avatars';
                    if (!is_dir($avatarDir) && !mkdir($avatarDir, 0755, true)) {
                        $errors[] = 'Could not create the avatars directory on the server.';
                    } else {
                        $ext = $allowedMimes[$mime];
                        $fileName = 'u' . $user['id'] . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
                        $destPath = $avatarDir . '/' . $fileName;
                        $dbPath   = 'avatars/' . $fileName;

                        if (move_uploaded_file($tmpPath, $destPath)) {
                            // Delete old avatar file if it exists inside avatars/
                            if (!empty($user['avatar']) && str_starts_with($user['avatar'], 'avatars/')) {
                                $oldFull = __DIR__ . '/' . $user['avatar'];
                                if (is_file($oldFull)) {
                                    @unlink($oldFull);
                                }
                            }

                            $upd = $pdo->prepare('UPDATE users SET avatar = ? WHERE id = ?');
                            $upd->execute([$dbPath, $user['id']]);
                            $user['avatar'] = $dbPath;
                            $success = 'Profile picture updated successfully.';
                        } else {
                            $errors[] = 'Failed to save uploaded avatar file.';
                        }
                    }
                }
            }
        }

        // 2. Remove Avatar
        elseif ($action === 'remove_avatar') {
            $activeTab = 'profile';
            if (!empty($user['avatar']) && str_starts_with($user['avatar'], 'avatars/')) {
                $oldFull = __DIR__ . '/' . $user['avatar'];
                if (is_file($oldFull)) {
                    @unlink($oldFull);
                }
            }
            $upd = $pdo->prepare('UPDATE users SET avatar = NULL WHERE id = ?');
            $upd->execute([$user['id']]);
            $user['avatar'] = null;
            $success = 'Profile picture removed.';
        }

        // 3. Change Username (7-day cooldown)
        elseif ($action === 'change_username') {
            $activeTab = 'profile';
            $newUsername = trim($_POST['new_username'] ?? '');

            // Check 7-day cooldown
            $cooldownSeconds = 7 * 86400;
            if (!empty($user['username_changed_at'])) {
                $lastChanged = strtotime($user['username_changed_at']);
                $nextAllowed = $lastChanged + $cooldownSeconds;
                if (time() < $nextAllowed) {
                    $daysLeft = ceil(($nextAllowed - time()) / 86400);
                    $errors[] = "You can only change your username once every 7 days. Next change available in {$daysLeft} day(s) (" . date('Y-m-d H:i', $nextAllowed) . ').';
                }
            }

            if (empty($errors)) {
                if ($newUsername === '') {
                    $errors[] = 'Please enter a new username.';
                } elseif (strtolower($newUsername) === strtolower($user['username'])) {
                    $errors[] = 'That is already your current username.';
                } elseif (!preg_match('/^[a-zA-Z0-9_]{3,20}$/', $newUsername)) {
                    $errors[] = 'Username must be 3-20 characters (letters, numbers, underscore only).';
                } elseif ($userRole !== 'admin' && ($policyErr = checkUsernamePolicy($newUsername)) !== null) {
                    $errors[] = $policyErr;
                } else {
                    $check = $pdo->prepare('SELECT id FROM users WHERE username = ? AND id != ? LIMIT 1');
                    $check->execute([$newUsername, $user['id']]);
                    if ($check->fetch()) {
                        $errors[] = 'This username is already taken.';
                    } else {
                        $upd = $pdo->prepare('UPDATE users SET username = ?, username_changed_at = NOW() WHERE id = ?');
                        $upd->execute([$newUsername, $user['id']]);
                        $user['username'] = $newUsername;
                        $user['username_changed_at'] = date('Y-m-d H:i:s');
                        $_SESSION['username'] = $newUsername;
                        $success = 'Username changed successfully. You can change it again in 7 days.';
                    }
                }
            }
        }

        // 4. Save Profile Customization (Bio, Status, Music, Background & Per-Card Colors)
        elseif ($action === 'save_profile_customization') {
            $activeTab = 'customize';

            $bio             = trim($_POST['bio'] ?? '');
            $statusText      = trim($_POST['status_text'] ?? '');
            $ageRaw          = trim($_POST['age'] ?? '');
            $interests       = trim($_POST['interests'] ?? '');
            $favoriteArtists = trim($_POST['favorite_artists'] ?? '');
            $favoriteMedia   = trim($_POST['favorite_media'] ?? '');
            $contacts        = trim($_POST['contacts'] ?? '');
            $musicTitle      = trim($_POST['profile_music_title'] ?? '');

            $ageVal = null;
            if ($ageRaw !== '') {
                if (!ctype_digit($ageRaw) || (int) $ageRaw < 13 || (int) $ageRaw > 120) {
                    $errors[] = 'Age must be a valid number between 13 and 120.';
                } else {
                    $ageVal = (int) $ageRaw;
                }
            }

            if (mb_strlen($bio) > 1000) {
                $errors[] = 'Bio must be 1000 characters or fewer.';
            }
            if (mb_strlen($statusText) > 120) {
                $errors[] = 'Status text must be 120 characters or fewer.';
            }
            if (mb_strlen($interests) > 500) {
                $errors[] = 'Interests must be 500 characters or fewer.';
            }
            if (mb_strlen($favoriteArtists) > 255) {
                $errors[] = 'Favorite artists must be 255 characters or fewer.';
            }
            if (mb_strlen($favoriteMedia) > 255) {
                $errors[] = 'Favorite movies, series & anime must be 255 characters or fewer.';
            }
            if (mb_strlen($contacts) > 300) {
                $errors[] = 'Contacts must be 300 characters or fewer.';
            }
            if (mb_strlen($musicTitle) > 100) {
                $errors[] = 'Music title must be 100 characters or fewer.';
            }

            // Optional MP3 audio file upload saved to /music/ (MP3 only, no external links)
            $finalMusicUrl = $user['profile_music_url'] ?? '';
            // Clear legacy external links if present so only local MP3 files are stored
            if ($finalMusicUrl !== '' && !str_starts_with($finalMusicUrl, 'music/')) {
                $finalMusicUrl = '';
            }

            if (isset($_POST['remove_music']) && $_POST['remove_music'] === '1') {
                if (!empty($finalMusicUrl) && str_starts_with($finalMusicUrl, 'music/')) {
                    $oldMusic = __DIR__ . '/' . $finalMusicUrl;
                    if (is_file($oldMusic)) {
                        @unlink($oldMusic);
                    }
                }
                $finalMusicUrl = '';
                $musicTitle = '';
            } elseif (!empty($_FILES['music_file']) && $_FILES['music_file']['error'] !== UPLOAD_ERR_NO_FILE) {
                if ($_FILES['music_file']['error'] !== UPLOAD_ERR_OK) {
                    $errors[] = 'Audio upload failed. Make sure the MP3 file is under 8 MB.';
                } elseif ($_FILES['music_file']['size'] > 8 * 1024 * 1024) {
                    $errors[] = 'Profile music file must be 8 MB or less.';
                } else {
                    $tmpAudio  = $_FILES['music_file']['tmp_name'];
                    $origExt   = strtolower(pathinfo($_FILES['music_file']['name'], PATHINFO_EXTENSION));
                    $finfo     = new finfo(FILEINFO_MIME_TYPE);
                    $audioMime = $finfo->file($tmpAudio);

                    // Verify MP3 magic bytes (ID3 tag or MPEG frame sync) in case Windows finfo returns octet-stream
                    $isMp3Header = false;
                    $fh = @fopen($tmpAudio, 'rb');
                    if ($fh) {
                        $head = fread($fh, 3);
                        fclose($fh);
                        if ($head === 'ID3' || (strlen($head) >= 2 && ord($head[0]) === 0xFF && (ord($head[1]) & 0xE0) === 0xE0)) {
                            $isMp3Header = true;
                        }
                    }

                    if ($origExt !== 'mp3' || (!in_array($audioMime, ['audio/mpeg', 'audio/mp3'], true) && !$isMp3Header)) {
                        $errors[] = 'Only MP3 (.mp3) audio files are allowed.';
                    } else {
                        $musicDir = __DIR__ . '/music';
                        if (!is_dir($musicDir) && !mkdir($musicDir, 0755, true)) {
                            $errors[] = 'Could not create the music directory on the server.';
                        } else {
                            $mName = 'm' . $user['id'] . '_' . bin2hex(random_bytes(5)) . '.mp3';
                            $mDest = $musicDir . '/' . $mName;
                            if (move_uploaded_file($tmpAudio, $mDest)) {
                                if (!empty($finalMusicUrl) && str_starts_with($finalMusicUrl, 'music/')) {
                                    $oldMusic = __DIR__ . '/' . $finalMusicUrl;
                                    if (is_file($oldMusic)) {
                                        @unlink($oldMusic);
                                    }
                                }
                                $finalMusicUrl = 'music/' . $mName;
                                if ($musicTitle === '') {
                                    $musicTitle = pathinfo($_FILES['music_file']['name'], PATHINFO_FILENAME);
                                    $musicTitle = mb_substr($musicTitle, 0, 100);
                                }
                            } else {
                                $errors[] = 'Failed to save uploaded MP3 file.';
                            }
                        }
                    }
                }
            }

            // Sanitize all hex colors
            $bgColor      = sanitizeHexColor($_POST['profile_bg_color'] ?? '', '#d0d7e1');
            $textColor    = sanitizeHexColor($_POST['profile_text_color'] ?? '', '#1a1a1a');
            $headerColor  = sanitizeHexColor($_POST['card_header_color'] ?? '', '#3b6a99');
            $profileCard  = sanitizeHexColor($_POST['card_profile_color'] ?? '', '#ffffff');
            $bioCard      = sanitizeHexColor($_POST['card_bio_color'] ?? '', '#ffffff');
            $musicCard    = sanitizeHexColor($_POST['card_music_color'] ?? '', '#ffffff');
            $extraCard    = sanitizeHexColor($_POST['card_extra_color'] ?? '', '#ffffff');

            if (empty($errors)) {
                $upd = $pdo->prepare(
                    'UPDATE users SET
                        bio = ?, status_text = ?,
                        age = ?, interests = ?, favorite_artists = ?, favorite_media = ?, contacts = ?,
                        profile_bg_color = ?, profile_text_color = ?, card_header_color = ?,
                        card_profile_color = ?, card_bio_color = ?, card_music_color = ?, card_extra_color = ?,
                        profile_music_title = ?, profile_music_url = ?
                     WHERE id = ?'
                );
                $upd->execute([
                    $bio !== '' ? $bio : null,
                    $statusText !== '' ? $statusText : null,
                    $ageVal,
                    $interests !== '' ? $interests : null,
                    $favoriteArtists !== '' ? $favoriteArtists : null,
                    $favoriteMedia !== '' ? $favoriteMedia : null,
                    $contacts !== '' ? $contacts : null,
                    $bgColor,
                    $textColor,
                    $headerColor,
                    $profileCard,
                    $bioCard,
                    $musicCard,
                    $extraCard,
                    $musicTitle !== '' ? $musicTitle : null,
                    $finalMusicUrl !== '' ? $finalMusicUrl : null,
                    $user['id'],
                ]);

                $user['bio'] = $bio;
                $user['status_text'] = $statusText;
                $user['age'] = $ageVal;
                $user['interests'] = $interests;
                $user['favorite_artists'] = $favoriteArtists;
                $user['favorite_media'] = $favoriteMedia;
                $user['contacts'] = $contacts;
                $user['profile_bg_color'] = $bgColor;
                $user['profile_text_color'] = $textColor;
                $user['card_header_color'] = $headerColor;
                $user['card_profile_color'] = $profileCard;
                $user['card_bio_color'] = $bioCard;
                $user['card_music_color'] = $musicCard;
                $user['card_extra_color'] = $extraCard;
                $user['profile_music_title'] = $musicTitle;
                $user['profile_music_url'] = $finalMusicUrl;

                $success = 'Profile appearance and music settings saved!';
            }
        }

        // 5. Reset Profile Colors to Default
        elseif ($action === 'reset_colors') {
            $activeTab = 'customize';
            $upd = $pdo->prepare(
                "UPDATE users SET
                    profile_bg_color = '#d0d7e1',
                    profile_text_color = '#1a1a1a',
                    card_header_color = '#3b6a99',
                    card_profile_color = '#ffffff',
                    card_bio_color = '#ffffff',
                    card_music_color = '#ffffff',
                    card_extra_color = '#ffffff'
                 WHERE id = ?"
            );
            $upd->execute([$user['id']]);
            $user['profile_bg_color']   = '#d0d7e1';
            $user['profile_text_color'] = '#1a1a1a';
            $user['card_header_color']  = '#3b6a99';
            $user['card_profile_color'] = '#ffffff';
            $user['card_bio_color']     = '#ffffff';
            $user['card_music_color']   = '#ffffff';
            $user['card_extra_color']   = '#ffffff';
            $success = 'Profile colors reset to classic DuskNet defaults.';
        }

        // 6. Update Email
        elseif ($action === 'update_email') {
            $activeTab = 'security';
            $newEmail = strtolower(trim($_POST['email'] ?? ''));
            $allowedDomains = ['gmail.com', 'outlook.com', 'outlook.com.tr'];

            if ($newEmail === '' || !filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'Please enter a valid email address.';
            } elseif (!in_array(substr(strrchr($newEmail, '@'), 1), $allowedDomains, true)) {
                $errors[] = 'Only Gmail (@gmail.com) and Outlook (@outlook.com / @outlook.com.tr) emails are allowed.';
            } elseif ($newEmail === strtolower($user['mail'])) {
                $errors[] = 'That is already your current email address.';
            } else {
                $check = $pdo->prepare('SELECT id FROM users WHERE mail = ? AND id != ? LIMIT 1');
                $check->execute([$newEmail, $user['id']]);
                if ($check->fetch()) {
                    $errors[] = 'This email address is already in use by another account.';
                } else {
                    $upd = $pdo->prepare('UPDATE users SET mail = ? WHERE id = ?');
                    $upd->execute([$newEmail, $user['id']]);
                    $user['mail'] = $newEmail;
                    $success = 'Email address updated successfully.';
                }
            }
        }

        // 7. Change Password
        elseif ($action === 'change_password') {
            $activeTab = 'security';
            $currentPass = $_POST['current_password'] ?? '';
            $newPass     = $_POST['new_password'] ?? '';
            $confirmPass = $_POST['confirm_password'] ?? '';

            if (!password_verify($currentPass, $user['password_hash'])) {
                $errors[] = 'Current password is incorrect.';
            } elseif (strlen($newPass) < 8) {
                $errors[] = 'New password must be at least 8 characters long.';
            } elseif (!preg_match('/[A-Z]/', $newPass) || !preg_match('/[a-z]/', $newPass) || !preg_match('/[0-9]/', $newPass)) {
                $errors[] = 'New password must include at least 1 uppercase letter, 1 lowercase letter, and 1 number.';
            } elseif ($newPass !== $confirmPass) {
                $errors[] = 'New passwords do not match.';
            } else {
                $newHash = password_hash($newPass, PASSWORD_DEFAULT);
                $upd = $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
                $upd->execute([$newHash, $user['id']]);
                $user['password_hash'] = $newHash;
                $success = 'Password changed successfully.';
            }
        }
    }
} catch (Throwable $e) {
    $errors[] = 'An unexpected database error occurred. Please try again later.';
}

// Username cooldown info for UI
$usernameLocked = false;
$usernameNextDate = '';
if (!empty($user['username_changed_at'])) {
    $nextAllowedTs = strtotime($user['username_changed_at']) + (7 * 86400);
    if (time() < $nextAllowedTs) {
        $usernameLocked = true;
        $usernameNextDate = date('Y-m-d H:i', $nextAllowedTs);
    }
}

$avatarSrc = (!empty($user['avatar']) && is_file(__DIR__ . '/' . $user['avatar'])) ? $user['avatar'] : '';
?>
<!DOCTYPE html>
<html lang="en" data-theme="blue">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Account - DuskNet</title>
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

        /* Main Layout */
        .content {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            padding: 12px;
        }

        .main-col { flex: 1 1 460px; min-width: 0; }
        .side-col { flex: 0 1 225px; min-width: 210px; margin: 0 auto; }

        /* Classic Account Tabs */
        .acc-tabs {
            display: flex;
            flex-wrap: wrap;
            gap: 4px;
            margin-bottom: 10px;
            border-bottom: 1px solid var(--border-strong);
            padding-bottom: 6px;
        }

        .acc-tab {
            background: linear-gradient(to bottom, var(--btn-top), var(--btn-bot));
            color: var(--btn-text);
            border: 1px solid var(--border-strong);
            padding: 3px 9px;
            font-size: 11px;
            font-weight: bold;
            text-decoration: none;
        }

        .acc-tab:hover { text-decoration: none; filter: brightness(0.96); }

        .acc-tab.active {
            background: linear-gradient(to bottom, var(--btn-primary-top), var(--btn-primary-bot));
            color: #ffffff;
            border-color: var(--btn-primary-border);
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
        }

        .box-body { padding: 10px 12px; }

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

        /* Info List */
        .info-list { list-style: none; margin: 0; padding: 0; font-size: 11px; }
        .info-list li {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 10px;
            padding: 5px 0;
            border-bottom: 1px dotted var(--border);
        }
        .info-list li:last-child { border-bottom: none; }
        .info-label { color: var(--text-muted); flex-shrink: 0; }
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

        /* Avatar Box */
        .avatar-row {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            align-items: flex-start;
        }

        .avatar-frame {
            width: 96px;
            height: 96px;
            border: 1px solid var(--border-strong);
            background-color: var(--bg-soft);
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            flex-shrink: 0;
            font-size: 28px;
            font-weight: bold;
            color: var(--text-muted);
        }

        .avatar-frame img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }

        /* Forms */
        .form-row { margin-bottom: 9px; }
        .form-row label { display: block; font-weight: bold; margin-bottom: 2px; }
        .form-row input[type="text"],
        .form-row input[type="email"],
        .form-row input[type="url"],
        .form-row input[type="password"],
        .form-row textarea {
            width: 100%;
            max-width: 360px;
            padding: 4px 6px;
            border: 1px solid var(--input-border);
            background-color: var(--input-bg);
            color: var(--text);
            font-family: inherit;
            font-size: 11px;
        }
        .form-row textarea { resize: vertical; min-height: 70px; }
        .form-row input[type="number"] {
            width: 110px;
            padding: 4px 6px;
            border: 1px solid var(--input-border);
            background-color: var(--input-bg);
            color: var(--text);
            font-family: inherit;
            font-size: 11px;
        }
        .form-row input:focus, .form-row textarea:focus { outline: none; border-color: var(--header-top); }
        .field-hint { font-size: 10px; color: var(--text-muted); margin-top: 2px; }

        /* Friends Grid in Overview */
        .friends-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(76px, 1fr));
            gap: 8px;
        }

        .friend-tile {
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

        .friend-tile:hover {
            border-color: var(--border-strong);
            text-decoration: none;
        }

        .friend-tile-avatar {
            width: 48px;
            height: 48px;
            border: 1px solid var(--border-strong);
            background-color: var(--bg-box);
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            font-size: 15px;
            font-weight: bold;
            color: var(--text-muted);
            margin-bottom: 4px;
        }

        .friend-tile-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }

        .friend-tile-name {
            font-size: 10px;
            font-weight: bold;
            color: var(--link);
            max-width: 100%;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .friend-tile:hover .friend-tile-name { text-decoration: underline; }
        .empty-note { color: var(--text-muted); font-style: italic; }

        /* Color Grid & Live Mini Preview */
        .color-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(185px, 1fr));
            gap: 8px;
            margin-bottom: 10px;
        }

        .color-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 6px;
            padding: 5px 7px;
            border: 1px solid var(--border);
            background-color: var(--bg-soft);
        }

        .color-item label { margin: 0; font-size: 10px; font-weight: bold; }
        .color-item input[type="color"] {
            width: 34px;
            height: 22px;
            padding: 0;
            border: 1px solid var(--border-strong);
            background: none;
            cursor: pointer;
        }

        .mini-preview {
            border: 1px solid var(--border-strong);
            padding: 10px;
            margin-bottom: 10px;
        }

        .mini-preview-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px;
        }

        .mini-card {
            border: 1px solid rgba(0, 0, 0, 0.35);
            font-size: 10px;
        }

        .mini-card-head {
            color: #ffffff;
            font-weight: bold;
            padding: 3px 6px;
            font-size: 10px;
        }

        .mini-card-body { padding: 6px; }

        /* Buttons */
        .btn {
            display: inline-block;
            background: linear-gradient(to bottom, var(--btn-top), var(--btn-bot));
            color: var(--btn-text);
            border: 1px solid var(--border-strong);
            box-shadow: inset 1px 1px 0 rgba(255, 255, 255, 0.5);
            padding: 3px 11px;
            font-family: inherit;
            font-size: 11px;
            font-weight: bold;
            cursor: pointer;
        }
        .btn:hover { text-decoration: none; filter: brightness(0.97); }
        .btn:disabled { opacity: 0.55; cursor: not-allowed; }
        .btn-primary {
            background: linear-gradient(to bottom, var(--btn-primary-top), var(--btn-primary-bot));
            color: #ffffff;
            border-color: var(--btn-primary-border);
            box-shadow: inset 1px 1px 0 rgba(255, 255, 255, 0.25);
        }

        .side-actions { display: flex; flex-direction: column; gap: 6px; }
        .side-actions .btn { text-align: center; }

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
                <input type="text" name="q" placeholder="Search users or rooms..." aria-label="Search users or rooms">
                <button type="submit">Search</button>
            </form>
            <div class="header-right">
                <div>
                    <strong><?= htmlspecialchars($user['username'], ENT_QUOTES, 'UTF-8') ?></strong><?= renderUserBadges($user['username'], $user['role']) ?><span class="sep">|</span><a href="account.php">My Account</a><span class="sep">|</span><a href="logout.php">Logout</a>
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
                <a href="account.php">My Account</a>
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

            <!-- Left Column -->
            <div class="main-col">

                <!-- Account Section Tabs -->
                <div class="acc-tabs">
                    <a href="account.php?tab=overview" class="acc-tab <?= $activeTab === 'overview' ? 'active' : '' ?>">Overview</a>
                    <a href="account.php?tab=profile" class="acc-tab <?= $activeTab === 'profile' ? 'active' : '' ?>">Avatar &amp; Username</a>
                    <a href="account.php?tab=customize" class="acc-tab <?= $activeTab === 'customize' ? 'active' : '' ?>">Profile Design &amp; Music</a>
                    <a href="account.php?tab=security" class="acc-tab <?= $activeTab === 'security' ? 'active' : '' ?>">Email &amp; Password</a>
                </div>

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

                <?php if ($activeTab === 'overview'): ?>
                    <!-- TAB 1: Overview -->
                    <section class="box">
                        <div class="box-title">Account Overview</div>
                        <div class="box-body">
                            <div class="avatar-row" style="margin-bottom: 10px;">
                                <div class="avatar-frame">
                                    <?php if ($avatarSrc !== ''): ?>
                                        <img src="<?= htmlspecialchars($avatarSrc, ENT_QUOTES, 'UTF-8') ?>" alt="Avatar">
                                    <?php else: ?>
                                        <?= htmlspecialchars(strtoupper(substr($user['username'], 0, 1)), ENT_QUOTES, 'UTF-8') ?>
                                    <?php endif; ?>
                                </div>
                                <div style="flex: 1; min-width: 180px;">
                                    <ul class="info-list">
                                        <li>
                                            <span class="info-label">Username</span>
                                            <span class="info-value">
                                                <strong><?= htmlspecialchars($user['username'], ENT_QUOTES, 'UTF-8') ?></strong><?= renderUserBadges($user['username'], $user['role']) ?>
                                            </span>
                                        </li>
                                        <li>
                                            <span class="info-label">Role</span>
                                            <span class="info-value">
                                                <?php if ($userRole === 'admin'): ?>
                                                    <strong class="role-admin">Admin</strong>
                                                <?php else: ?>
                                                    <strong><?= htmlspecialchars(strtoupper($userRole === 'vip' ? 'VIP' : ucfirst($userRole)), ENT_QUOTES, 'UTF-8') ?></strong>
                                                <?php endif; ?>
                                            </span>
                                        </li>
                                        <li>
                                            <span class="info-label">Email</span>
                                            <span class="info-value"><?= htmlspecialchars($user['mail'], ENT_QUOTES, 'UTF-8') ?></span>
                                        </li>
                                        <li>
                                            <span class="info-label">GIF Avatar</span>
                                            <span class="info-value"><?= $canUseGif ? '<strong>Unlocked</strong>' : 'VIP / Mod Only' ?></span>
                                        </li>
                                        <li>
                                            <span class="info-label">Member Since</span>
                                            <span class="info-value"><?= htmlspecialchars($user['created_at'], ENT_QUOTES, 'UTF-8') ?></span>
                                        </li>
                                        <li>
                                            <span class="info-label">Friends</span>
                                            <span class="info-value"><strong><?= $myFriendsCount ?></strong></span>
                                        </li>
                                    </ul>
                                </div>
                            </div>
                            <a href="account.php?tab=customize" class="btn btn-primary">Customize Profile</a>
                            <a href="profile.php?u=<?= urlencode($user['username']) ?>" class="btn">View Public Profile</a>
                        </div>
                    </section>

                    <section class="box">
                        <div class="box-title">My Friends (<?= $myFriendsCount ?>)</div>
                        <div class="box-body">
                            <?php if (!empty($myFriends)): ?>
                                <div class="friends-grid">
                                    <?php foreach ($myFriends as $fr): ?>
                                        <?php
                                        $frAv = (!empty($fr['avatar']) && !str_contains($fr['avatar'], '..') && is_file(__DIR__ . '/' . $fr['avatar']))
                                            ? $fr['avatar']
                                            : '';
                                        ?>
                                        <a href="profile.php?u=<?= urlencode($fr['username']) ?>" class="friend-tile" title="View <?= htmlspecialchars($fr['username'], ENT_QUOTES, 'UTF-8') ?>'s profile">
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
                                <span class="empty-note">You haven't added any friends yet. Visit member profiles to send friend requests.</span>
                            <?php endif; ?>
                        </div>
                    </section>

                <?php elseif ($activeTab === 'profile'): ?>
                    <!-- TAB 2: Avatar Upload & Username Change -->
                    <section class="box">
                        <div class="box-title">Profile Picture (Avatar)</div>
                        <div class="box-body">
                            <div class="avatar-row">
                                <div class="avatar-frame">
                                    <?php if ($avatarSrc !== ''): ?>
                                        <img src="<?= htmlspecialchars($avatarSrc, ENT_QUOTES, 'UTF-8') ?>" alt="Current Avatar">
                                    <?php else: ?>
                                        <?= htmlspecialchars(strtoupper(substr($user['username'], 0, 1)), ENT_QUOTES, 'UTF-8') ?>
                                    <?php endif; ?>
                                </div>
                                <div style="flex: 1; min-width: 210px;">
                                    <form action="account.php?tab=profile" method="post" enctype="multipart/form-data">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                                        <input type="hidden" name="action" value="upload_avatar">
                                        <input type="hidden" name="tab" value="profile">

                                        <div class="form-row">
                                            <label for="avatar">Choose Image (Max 3 MB)</label>
                                            <input type="file" id="avatar" name="avatar" accept="<?= $canUseGif ? '.jpg,.jpeg,.png,.webp,.gif' : '.jpg,.jpeg,.png,.webp' ?>" required>
                                            <div class="field-hint">
                                                Saved to <code>/avatars/</code>. Allowed: JPG, PNG, WEBP<?= $canUseGif ? ', <strong>GIF (Unlocked)</strong>' : ' (GIF requires <strong>Mod</strong> or <strong>VIP</strong>)' ?>.
                                            </div>
                                        </div>

                                        <button type="submit" class="btn btn-primary">Upload Avatar</button>
                                    </form>

                                    <?php if ($avatarSrc !== ''): ?>
                                        <form action="account.php?tab=profile" method="post" style="margin-top: 6px;">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                                            <input type="hidden" name="action" value="remove_avatar">
                                            <input type="hidden" name="tab" value="profile">
                                            <button type="submit" class="btn">Remove Avatar</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </section>

                    <section class="box">
                        <div class="box-title">Change Username (7-Day Cooldown)</div>
                        <div class="box-body">
                            <?php if ($usernameLocked): ?>
                                <div class="msg-box msg-error" style="margin-bottom: 8px;">
                                    You recently changed your username. You can change it again after <strong><?= htmlspecialchars($usernameNextDate, ENT_QUOTES, 'UTF-8') ?></strong>.
                                </div>
                            <?php endif; ?>

                            <form action="account.php?tab=profile" method="post" autocomplete="off">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="action" value="change_username">
                                <input type="hidden" name="tab" value="profile">

                                <div class="form-row">
                                    <label for="new_username">New Username</label>
                                    <input
                                        type="text"
                                        id="new_username"
                                        name="new_username"
                                        maxlength="20"
                                        value="<?= htmlspecialchars($user['username'], ENT_QUOTES, 'UTF-8') ?>"
                                        <?= $usernameLocked ? 'disabled' : 'required' ?>
                                    >
                                    <div class="field-hint">
                                        3-20 characters (letters, numbers, underscore). Once changed, you must wait <strong>1 week (7 days)</strong> before changing it again.
                                    </div>
                                </div>

                                <button type="submit" class="btn btn-primary" <?= $usernameLocked ? 'disabled' : '' ?>>Change Username</button>
                            </form>
                        </div>
                    </section>

                <?php elseif ($activeTab === 'customize'): ?>
                    <!-- TAB 3: Profile Editing, Background Music & Per-Card Colors -->
                    <section class="box">
                        <div class="box-title">Edit Profile, Music &amp; Colors</div>
                        <div class="box-body">
                            <form action="account.php?tab=customize" method="post" enctype="multipart/form-data">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="action" value="save_profile_customization">
                                <input type="hidden" name="tab" value="customize">

                                <div class="form-row">
                                    <label for="status_text">Status Message</label>
                                    <input type="text" id="status_text" name="status_text" maxlength="120" placeholder="Listening to music..." value="<?= htmlspecialchars($user['status_text'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                                </div>

                                <div class="form-row">
                                    <label for="bio">About Me (Bio)</label>
                                    <textarea id="bio" name="bio" maxlength="1000" placeholder="Write something about yourself..."><?= htmlspecialchars($user['bio'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
                                    <div class="field-hint">Shown on your public profile page (max 1000 chars).</div>
                                </div>

                                <div class="form-row">
                                    <label for="age">Age (Optional)</label>
                                    <input type="number" id="age" name="age" min="13" max="120" placeholder="e.g. 21" value="<?= htmlspecialchars((string) ($user['age'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                                </div>

                                <div class="form-row">
                                    <label for="interests">Interests / Hobbies</label>
                                    <textarea id="interests" name="interests" maxlength="500" style="min-height: 54px;" placeholder="Retro web, coding, gaming, late night chats..."><?= htmlspecialchars($user['interests'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
                                    <div class="field-hint">Things you are into (max 500 chars).</div>
                                </div>

                                <div class="form-row">
                                    <label for="favorite_artists">Favorite Artists / Musicians</label>
                                    <input type="text" id="favorite_artists" name="favorite_artists" maxlength="255" placeholder="e.g. Radiohead, Daft Punk, Mor ve Ötesi..." value="<?= htmlspecialchars($user['favorite_artists'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                                </div>

                                <div class="form-row">
                                    <label for="favorite_media">Favorite Series, Movies &amp; Anime</label>
                                    <input type="text" id="favorite_media" name="favorite_media" maxlength="255" placeholder="e.g. Breaking Bad, Interstellar, Cowboy Bebop..." value="<?= htmlspecialchars($user['favorite_media'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                                </div>

                                <div class="form-row">
                                    <label for="contacts">Contacts (Discord, Instagram, Steam, etc.)</label>
                                    <textarea id="contacts" name="contacts" maxlength="300" style="min-height: 54px;" placeholder="Discord: username&#10;Instagram: @handle&#10;Steam: ..."><?= htmlspecialchars($user['contacts'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
                                    <div class="field-hint">Public contact handles shown on your profile (max 300 chars).</div>
                                </div>

                                <hr style="border: 0; border-top: 1px dotted var(--border); margin: 12px 0;">

                                <!-- Profile Background Music (MP3 Only) -->
                                <div style="font-weight: bold; margin-bottom: 6px;">&#9835; Profile Background Music (MP3 Only)</div>

                                <div class="form-row">
                                    <label for="profile_music_title">Track Title</label>
                                    <input type="text" id="profile_music_title" name="profile_music_title" maxlength="100" placeholder="e.g. Artist - Song Name" value="<?= htmlspecialchars($user['profile_music_title'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                                    <div class="field-hint">Optional display title (defaults to the MP3 filename if left blank).</div>
                                </div>

                                <div class="form-row">
                                    <label for="music_file">Upload MP3 File (Max 8 MB)</label>
                                    <input type="file" id="music_file" name="music_file" accept=".mp3,audio/mpeg">
                                    <div class="field-hint">Saved to <code>/music/</code>. Only <strong>.mp3</strong> files are accepted (external links are disabled).</div>
                                </div>

                                <?php
                                $currentMusicPath = (!empty($user['profile_music_url']) && str_starts_with($user['profile_music_url'], 'music/') && is_file(__DIR__ . '/' . $user['profile_music_url']))
                                    ? $user['profile_music_url']
                                    : '';
                                ?>
                                <?php if ($currentMusicPath !== ''): ?>
                                    <div class="form-row" style="padding: 6px 8px; border: 1px solid var(--border); background-color: var(--bg-soft); max-width: 360px;">
                                        <div style="font-size: 10px; margin-bottom: 4px;">
                                            Current Track: <strong><?= htmlspecialchars($user['profile_music_title'] ?: basename($currentMusicPath), ENT_QUOTES, 'UTF-8') ?></strong>
                                        </div>
                                        <audio controls preload="none" style="width: 100%; height: 28px; margin-bottom: 5px;">
                                            <source src="<?= htmlspecialchars($currentMusicPath, ENT_QUOTES, 'UTF-8') ?>" type="audio/mpeg">
                                        </audio>
                                        <label style="font-weight: normal; margin: 0;">
                                            <input type="checkbox" name="remove_music" value="1">
                                            Remove current background music
                                        </label>
                                    </div>
                                <?php endif; ?>

                                <hr style="border: 0; border-top: 1px dotted var(--border); margin: 12px 0;">

                                <!-- Profile Background & Individual Card Colors -->
                                <div style="font-weight: bold; margin-bottom: 6px;">Profile Background &amp; Individual Card Colors</div>
                                <div class="color-grid">
                                    <div class="color-item">
                                        <label for="profile_bg_color">Page Background</label>
                                        <input type="color" id="profile_bg_color" name="profile_bg_color" value="<?= htmlspecialchars($user['profile_bg_color'] ?? '#d0d7e1', ENT_QUOTES, 'UTF-8') ?>">
                                    </div>
                                    <div class="color-item">
                                        <label for="profile_text_color">Profile Text</label>
                                        <input type="color" id="profile_text_color" name="profile_text_color" value="<?= htmlspecialchars($user['profile_text_color'] ?? '#1a1a1a', ENT_QUOTES, 'UTF-8') ?>">
                                    </div>
                                    <div class="color-item">
                                        <label for="card_header_color">Card Title Bars</label>
                                        <input type="color" id="card_header_color" name="card_header_color" value="<?= htmlspecialchars($user['card_header_color'] ?? '#3b6a99', ENT_QUOTES, 'UTF-8') ?>">
                                    </div>
                                    <div class="color-item">
                                        <label for="card_profile_color">Profile Info Card</label>
                                        <input type="color" id="card_profile_color" name="card_profile_color" value="<?= htmlspecialchars($user['card_profile_color'] ?? '#ffffff', ENT_QUOTES, 'UTF-8') ?>">
                                    </div>
                                    <div class="color-item">
                                        <label for="card_bio_color">About Me (Bio) Card</label>
                                        <input type="color" id="card_bio_color" name="card_bio_color" value="<?= htmlspecialchars($user['card_bio_color'] ?? '#ffffff', ENT_QUOTES, 'UTF-8') ?>">
                                    </div>
                                    <div class="color-item">
                                        <label for="card_music_color">Music Player Card</label>
                                        <input type="color" id="card_music_color" name="card_music_color" value="<?= htmlspecialchars($user['card_music_color'] ?? '#ffffff', ENT_QUOTES, 'UTF-8') ?>">
                                    </div>
                                    <div class="color-item">
                                        <label for="card_extra_color">Details / Wall Card</label>
                                        <input type="color" id="card_extra_color" name="card_extra_color" value="<?= htmlspecialchars($user['card_extra_color'] ?? '#ffffff', ENT_QUOTES, 'UTF-8') ?>">
                                    </div>
                                </div>

                                <!-- Live Mini Preview -->
                                <div class="field-hint" style="margin-bottom: 4px;">Live Profile Color Preview:</div>
                                <div class="mini-preview" id="miniPreview" style="background-color: <?= htmlspecialchars($user['profile_bg_color'] ?? '#d0d7e1', ENT_QUOTES, 'UTF-8') ?>; color: <?= htmlspecialchars($user['profile_text_color'] ?? '#1a1a1a', ENT_QUOTES, 'UTF-8') ?>;">
                                    <div class="mini-preview-grid">
                                        <div class="mini-card" id="prevCardProfile" style="background-color: <?= htmlspecialchars($user['card_profile_color'] ?? '#ffffff', ENT_QUOTES, 'UTF-8') ?>;">
                                            <div class="mini-card-head" style="background-color: <?= htmlspecialchars($user['card_header_color'] ?? '#3b6a99', ENT_QUOTES, 'UTF-8') ?>;">Profile Card</div>
                                            <div class="mini-card-body"><strong><?= htmlspecialchars($user['username'], ENT_QUOTES, 'UTF-8') ?></strong></div>
                                        </div>
                                        <div class="mini-card" id="prevCardBio" style="background-color: <?= htmlspecialchars($user['card_bio_color'] ?? '#ffffff', ENT_QUOTES, 'UTF-8') ?>;">
                                            <div class="mini-card-head" style="background-color: <?= htmlspecialchars($user['card_header_color'] ?? '#3b6a99', ENT_QUOTES, 'UTF-8') ?>;">About Me</div>
                                            <div class="mini-card-body">Bio text sample</div>
                                        </div>
                                        <div class="mini-card" id="prevCardMusic" style="background-color: <?= htmlspecialchars($user['card_music_color'] ?? '#ffffff', ENT_QUOTES, 'UTF-8') ?>;">
                                            <div class="mini-card-head" style="background-color: <?= htmlspecialchars($user['card_header_color'] ?? '#3b6a99', ENT_QUOTES, 'UTF-8') ?>;">Profile Music</div>
                                            <div class="mini-card-body">&#9835; Background audio</div>
                                        </div>
                                        <div class="mini-card" id="prevCardExtra" style="background-color: <?= htmlspecialchars($user['card_extra_color'] ?? '#ffffff', ENT_QUOTES, 'UTF-8') ?>;">
                                            <div class="mini-card-head" style="background-color: <?= htmlspecialchars($user['card_header_color'] ?? '#3b6a99', ENT_QUOTES, 'UTF-8') ?>;">Details / Wall</div>
                                            <div class="mini-card-body">Extra card info</div>
                                        </div>
                                    </div>
                                </div>

                                <div style="display: flex; flex-wrap: wrap; gap: 6px;">
                                    <button type="submit" class="btn btn-primary">Save Profile Settings</button>
                                </div>
                            </form>

                            <form action="account.php?tab=customize" method="post" style="margin-top: 8px;">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="action" value="reset_colors">
                                <input type="hidden" name="tab" value="customize">
                                <button type="submit" class="btn">Reset Colors to Default</button>
                            </form>
                        </div>
                    </section>

                <?php elseif ($activeTab === 'security'): ?>
                    <!-- TAB 4: Email & Password -->
                    <section class="box">
                        <div class="box-title">Update Email Address</div>
                        <div class="box-body">
                            <form action="account.php?tab=security" method="post">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="action" value="update_email">
                                <input type="hidden" name="tab" value="security">

                                <div class="form-row">
                                    <label for="email">New Email Address (Gmail or Outlook only)</label>
                                    <input type="email" id="email" name="email" value="<?= htmlspecialchars($user['mail'], ENT_QUOTES, 'UTF-8') ?>" required>
                                    <div class="field-hint">Your email is kept private and never displayed publicly.</div>
                                </div>

                                <button type="submit" class="btn btn-primary">Save Email</button>
                            </form>
                        </div>
                    </section>

                    <section class="box">
                        <div class="box-title">Change Password</div>
                        <div class="box-body">
                            <form action="account.php?tab=security" method="post" autocomplete="off">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="action" value="change_password">
                                <input type="hidden" name="tab" value="security">

                                <div class="form-row">
                                    <label for="current_password">Current Password</label>
                                    <input type="password" id="current_password" name="current_password" required>
                                </div>

                                <div class="form-row">
                                    <label for="new_password">New Password</label>
                                    <input type="password" id="new_password" name="new_password" minlength="8" required>
                                    <div class="field-hint">Min 8 chars, 1 uppercase, 1 lowercase, and 1 number.</div>
                                </div>

                                <div class="form-row">
                                    <label for="confirm_password">Confirm New Password</label>
                                    <input type="password" id="confirm_password" name="confirm_password" minlength="8" required>
                                </div>

                                <button type="submit" class="btn btn-primary">Update Password</button>
                            </form>
                        </div>
                    </section>
                <?php endif; ?>

            </div>

            <!-- Right Column: Quick Links -->
            <aside class="side-col">
                <section class="box">
                    <div class="box-title">Account Menu</div>
                    <div class="box-body">
                        <div class="side-actions">
                            <a href="index.php" class="btn">Back to Home</a>
                            <a href="profile.php?u=<?= urlencode($user['username']) ?>" class="btn btn-primary">View Public Profile</a>
                            <a href="logout.php" class="btn">Logout</a>
                        </div>
                    </div>
                </section>

                <section class="box">
                    <div class="box-title">Perks &amp; Limits</div>
                    <div class="box-body" style="color: var(--text-muted);">
                        <div>&bull; Avatar max size: <strong>3 MB</strong></div>
                        <div>&bull; GIF Avatars: <strong><?= $canUseGif ? 'Enabled' : 'Mod / VIP only' ?></strong></div>
                        <div>&bull; Username change: <strong>1x / 7 days</strong></div>
                        <div>&bull; Music upload: <strong>MP3 only (8 MB)</strong></div>
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

        /* Live Mini Preview for Profile Colors */
        const preview = document.getElementById('miniPreview');
        if (preview) {
            const bindColor = (id, fn) => {
                const el = document.getElementById(id);
                if (el) el.addEventListener('input', () => fn(el.value));
            };

            bindColor('profile_bg_color', val => preview.style.backgroundColor = val);
            bindColor('profile_text_color', val => preview.style.color = val);
            bindColor('card_header_color', val => {
                preview.querySelectorAll('.mini-card-head').forEach(h => h.style.backgroundColor = val);
            });
            bindColor('card_profile_color', val => {
                const c = document.getElementById('prevCardProfile');
                if (c) c.style.backgroundColor = val;
            });
            bindColor('card_bio_color', val => {
                const c = document.getElementById('prevCardBio');
                if (c) c.style.backgroundColor = val;
            });
            bindColor('card_music_color', val => {
                const c = document.getElementById('prevCardMusic');
                if (c) c.style.backgroundColor = val;
            });
            bindColor('card_extra_color', val => {
                const c = document.getElementById('prevCardExtra');
                if (c) c.style.backgroundColor = val;
            });
        }
    </script>

</body>
</html>