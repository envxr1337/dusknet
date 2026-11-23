<?php
session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/smtp.php';

// Allowed email domains (Gmail & Outlook only)
const ALLOWED_EMAIL_DOMAINS = [
    'gmail.com',
    'outlook.com',
    'outlook.com.tr',
];

/**
 * Normalizes a username to catch leetspeak (0->o, 1->i, 3->e, 5->s),
 * underscores, and repeated characters (e.g. du5k_n3ttt -> dusknet).
 */
function normalizeForFilter(string $text): string
{
    $text = strtolower($text);
    $map = [
        '0' => 'o',
        '1' => 'i',
        '3' => 'e',
        '4' => 'a',
        '5' => 's',
        '7' => 't',
        '8' => 'b',
        '9' => 'g',
        '$' => 's',
        '@' => 'a',
    ];
    $text = strtr($text, $map);
    $lettersOnly = preg_replace('/[^a-z]/', '', $text);
    // Collapse 2+ consecutive identical letters (e.g., fuuuck -> fuck, duskneet -> dusknet)
    return preg_replace('/(.)\1+/', '$1', $lettersOnly);
}

/**
 * Blocks DuskNet lookalikes, reserved staff names, and multi-language profanity/slurs.
 * Returns an error message string if blocked, or null if allowed.
 */
function checkUsernamePolicy(string $username): ?string
{
    $rawLower   = strtolower($username);
    $norm       = normalizeForFilter($username);
    $noSymbols  = preg_replace('/[^a-z]/', '', $rawLower);

    // 1. Block exact/substring DuskNet and close lookalikes (dusknetx, dusknet1, dusknxt, du5knet, dsknet, etc.)
    if (str_contains($norm, 'dusknet') || str_contains($noSymbols, 'dusknet')) {
        return 'Usernames containing or resembling "DuskNet" are not allowed.';
    }

    // Sliding window Levenshtein check catches 1-char typos/swaps like "dusknxt", "dusknt", "duskmet", "duskxnet"
    $len = strlen($norm);
    foreach ([6, 7, 8] as $win) {
        if ($len >= $win) {
            for ($i = 0; $i <= $len - $win; $i++) {
                $chunk = substr($norm, $i, $win);
                if (levenshtein($chunk, 'dusknet') <= 1) {
                    return 'Usernames similar to "DuskNet" are not allowed.';
                }
            }
        }
    }

    // 2. Block reserved staff/system words
    $reserved = ['admin', 'moderator', 'official', 'support', 'staff', 'sysadmin', 'webmaster'];
    foreach ($reserved as $word) {
        if (str_contains($norm, $word)) {
            return 'This username contains a reserved system word.';
        }
    }

    // 3. Multi-language profanity, slurs & hate speech filter (EN, TR, DE, ES, FR, RU, PT, IT)
    $blockedTerms = [
        // English slurs & profanity
        'nigger', 'nigga', 'nigr', 'faggot', 'fag', 'retard', 'chink', 'kike', 'spic',
        'fuck', 'fuk', 'fck', 'shit', 'bitch', 'cunt', 'whore', 'slut', 'bastard',
        'dickhead', 'asshole', 'motherfucker', 'cocksucker', 'blowjob', 'porn', 'rape', 'nazi', 'hitler',
        // Turkish profanity & insults
        'siktir', 'sikim', 'sikeyim', 'orospu', 'amcik', 'amk', 'aq', 'yarrak', 'yarak',
        'piç', 'pic', 'gavat', 'pezevenk', 'kahpe', 'gotveren', 'ibne', 'oc', 'oç', 'ananisik',
        // Spanish / Portuguese / French / German / Italian / Russian (transliterated)
        'puta', 'mierda', 'pendejo', 'cabron', 'maricon', 'caralho', 'porra', 'filhodaputa',
        'putain', 'salope', 'connard', 'encule', 'merde',
        'scheisse', 'arschloch', 'hurensohn', 'fotze', 'wichser',
        'vaffanculo', 'stronzo', 'troia', 'cazzo',
        'blyat', 'suka', 'cyka', 'pizdec', 'nahui', 'ebat', 'pidor',
    ];

    // Very short terms are checked only on exact match to avoid false positives (e.g. "pic" in "picture")
    $exactOnly = ['aq', 'oc', 'oç', 'pic', 'piç', 'fag'];

    foreach ($blockedTerms as $term) {
        $normTerm = normalizeForFilter($term);
        if (in_array($term, $exactOnly, true)) {
            if ($rawLower === $term || $norm === $normTerm) {
                return 'This username contains inappropriate or offensive language.';
            }
        } else {
            if (str_contains($norm, $normTerm) || str_contains($rawLower, $term)) {
                return 'This username contains inappropriate or offensive language.';
            }
        }
    }

    return null;
}

// Generate CSRF token for form security
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Ensure expires_at column exists & automatically purge expired 24h accounts
try {
    $colCheck = $pdo->query("SHOW COLUMNS FROM users LIKE 'expires_at'")->fetch();
    if (!$colCheck) {
        $pdo->exec('ALTER TABLE users ADD COLUMN expires_at TIMESTAMP NULL DEFAULT NULL');
    }
    $pdo->exec('DELETE FROM users WHERE expires_at IS NOT NULL AND expires_at <= NOW()');
} catch (Throwable $e) {
    // Keep page functional if schema check fails
}

/**
 * Sends a 6-digit verification code using smtp.php (sendDuskNetMail).
 */
function sendVerificationCode(string $toEmail, string $username, string $code, bool $isTemp): array
{
    $accountNote = $isTemp
        ? 'Account Type: 24-Hour Temporary Account (auto-deletes after 24 hours)'
        : 'Account Type: Standard Permanent Account';

    $subject = 'DuskNet Verification Code: ' . $code;
    $body    = "Hello {$username},\n\n"
        . "Your DuskNet registration verification code is:\n\n"
        . "    {$code}\n\n"
        . "{$accountNote}\n"
        . "This code expires in 10 minutes. Do not share it with anyone.\n\n"
        . "If you did not request this, you can safely ignore this email.\n"
        . "- DuskNet";

    return sendDuskNetMail($toEmail, $username, $subject, $body, false);
}

$errors = [];
$notices = [];
$success = '';
$username = '';
$email = '';
$accountType = 'permanent';

// Clear expired pending registration automatically
if (!empty($_SESSION['pending_reg']) && time() > ($_SESSION['pending_reg']['expires_at'] ?? 0)) {
    unset($_SESSION['pending_reg']);
    $notices[] = 'Your previous verification code expired. Please submit the form again.';
}

// Simple session-based rate limiter (max 6 code sends per 10 minutes)
if (!isset($_SESSION['reg_rate'])) {
    $_SESSION['reg_rate'] = ['count' => 0, 'window_start' => time()];
}
if (time() - $_SESSION['reg_rate']['window_start'] > 600) {
    $_SESSION['reg_rate'] = ['count' => 0, 'window_start' => time()];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token  = $_POST['csrf_token'] ?? '';
    $action = $_POST['action'] ?? 'start_register';

    // 1. CSRF check
    if (!hash_equals($_SESSION['csrf_token'], $token)) {
        $errors[] = 'Security check failed (invalid token). Please refresh and try again.';
    }

    // 2. Honeypot anti-bot check (must remain empty)
    if (!empty($_POST['website'])) {
        $errors[] = 'Automated submission blocked.';
    }

    if (empty($errors)) {
        // ACTION A: Cancel pending verification and return to Step 1
        if ($action === 'cancel_verify') {
            unset($_SESSION['pending_reg']);
            $notices[] = 'Registration cancelled. You can edit your details below.';
        }

        // ACTION B: Resend 6-digit verification code (60s cooldown)
        elseif ($action === 'resend_code' && !empty($_SESSION['pending_reg'])) {
            $pending = $_SESSION['pending_reg'];
            $wait = 60 - (time() - ($pending['last_sent'] ?? 0));

            if ($wait > 0) {
                $errors[] = "Please wait {$wait} seconds before requesting a new code.";
            } elseif ($_SESSION['reg_rate']['count'] >= 6) {
                $errors[] = 'Too many verification requests. Please wait a few minutes.';
            } else {
                $newCode = (string) random_int(100000, 999999);
                $send = sendVerificationCode($pending['email'], $pending['username'], $newCode, $pending['is_temp']);

                if ($send['ok']) {
                    $_SESSION['pending_reg']['code_hash']  = password_hash($newCode, PASSWORD_DEFAULT);
                    $_SESSION['pending_reg']['expires_at'] = time() + 600;
                    $_SESSION['pending_reg']['last_sent']  = time();
                    $_SESSION['pending_reg']['attempts']   = 0;
                    $_SESSION['reg_rate']['count']++;
                    $notices[] = 'A new 6-digit verification code has been sent to your email.';
                } else {
                    $errors[] = $send['error'];
                }
            }
        }

        // ACTION C: Verify 6-digit code and create account in DB
        elseif ($action === 'verify_code' && !empty($_SESSION['pending_reg'])) {
            $inputCode = preg_replace('/\D+/', '', $_POST['verify_code'] ?? '');
            $pending   = $_SESSION['pending_reg'];

            if (time() > $pending['expires_at']) {
                unset($_SESSION['pending_reg']);
                $errors[] = 'Verification code has expired. Please register again.';
            } elseif ($pending['attempts'] >= 5) {
                unset($_SESSION['pending_reg']);
                $errors[] = 'Too many incorrect code attempts. Please start over.';
            } elseif (strlen($inputCode) !== 6) {
                $errors[] = 'Please enter the 6-digit numeric code sent to your email.';
            } elseif (!password_verify($inputCode, $pending['code_hash'])) {
                $_SESSION['pending_reg']['attempts']++;
                $left = 5 - $_SESSION['pending_reg']['attempts'];
                $errors[] = "Incorrect verification code. {$left} attempt(s) remaining.";
            } else {
                // Code verified -> create account in database
                try {
                    $checkUser = $pdo->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
                    $checkUser->execute([$pending['username']]);
                    $checkMail = $pdo->prepare('SELECT id FROM users WHERE mail = ? LIMIT 1');
                    $checkMail->execute([$pending['email']]);

                    if ($checkUser->fetch()) {
                        unset($_SESSION['pending_reg']);
                        $errors[] = 'This username is already taken.';
                    } elseif ($checkMail->fetch()) {
                        unset($_SESSION['pending_reg']);
                        $errors[] = 'This email address is already registered.';
                    } else {
                        $expiresAt = $pending['is_temp']
                            ? date('Y-m-d H:i:s', time() + 86400)
                            : null;

                        $stmt = $pdo->prepare(
                            'INSERT INTO users (username, password_hash, role, mail, ip, expires_at) VALUES (?, ?, ?, ?, ?, ?)'
                        );
                        $stmt->execute([
                            $pending['username'],
                            $pending['password_hash'],
                            'user',
                            $pending['email'],
                            $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
                            $expiresAt
                        ]);

                        $createdName = htmlspecialchars($pending['username'], ENT_QUOTES, 'UTF-8');
                        $tempNote = $pending['is_temp']
                            ? ' (This temporary account will automatically expire in 24 hours.)'
                            : '';

                        unset($_SESSION['pending_reg']);
                        // Rotate CSRF token after successful account creation
                        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                        $success = "Account \"{$createdName}\" verified and created successfully!{$tempNote}";
                    }
                } catch (Throwable $e) {
                    $errors[] = 'Database error while finalizing registration. Please try again.';
                }
            }
        }

        // ACTION D: Step 1 - Validate form & send PHPMailer verification code
        elseif ($action === 'start_register') {
            $username        = trim($_POST['username'] ?? '');
            $email           = strtolower(trim($_POST['email'] ?? ''));
            $password        = $_POST['password'] ?? '';
            $confirmPassword = $_POST['confirm_password'] ?? '';
            $accountType     = ($_POST['account_type'] ?? 'permanent') === 'temp24' ? 'temp24' : 'permanent';
            $agreeRules      = isset($_POST['agree_rules']);

            if ($_SESSION['reg_rate']['count'] >= 6) {
                $errors[] = 'Too many registration attempts. Please wait 10 minutes.';
            }

            // Validate username
            if ($username === '') {
                $errors[] = 'Username is required.';
            } elseif (!preg_match('/^[a-zA-Z0-9_]{3,20}$/', $username)) {
                $errors[] = 'Username must be 3-20 characters (letters, numbers, underscore only).';
            } elseif (($policyError = checkUsernamePolicy($username)) !== null) {
                $errors[] = $policyError;
            } else {
                try {
                    $checkUser = $pdo->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
                    $checkUser->execute([$username]);
                    if ($checkUser->fetch()) {
                        $errors[] = 'This username is already taken.';
                    }
                } catch (Throwable $e) {
                    $errors[] = 'Could not verify username availability.';
                }
            }

            // Validate email + strict Gmail / Outlook domain check
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'Please enter a valid email address.';
            } else {
                $domain = substr(strrchr($email, '@'), 1);
                if (!in_array($domain, ALLOWED_EMAIL_DOMAINS, true)) {
                    $errors[] = 'Only Gmail (@gmail.com) and Outlook (@outlook.com / @outlook.com.tr) addresses are accepted.';
                } else {
                    try {
                        $checkMail = $pdo->prepare('SELECT id FROM users WHERE mail = ? LIMIT 1');
                        $checkMail->execute([$email]);
                        if ($checkMail->fetch()) {
                            $errors[] = 'This email address is already registered.';
                        }
                    } catch (Throwable $e) {
                        $errors[] = 'Could not verify email availability.';
                    }
                }
            }

            // Stronger password validation
            if (strlen($password) < 8) {
                $errors[] = 'Password must be at least 8 characters long.';
            } elseif (!preg_match('/[A-Z]/', $password)) {
                $errors[] = 'Password must include at least one uppercase letter (A-Z).';
            } elseif (!preg_match('/[a-z]/', $password) || !preg_match('/[0-9]/', $password)) {
                $errors[] = 'Password must include at least one lowercase letter and one number.';
            } elseif ($password !== $confirmPassword) {
                $errors[] = 'Passwords do not match.';
            }

            if (!$agreeRules) {
                $errors[] = 'You must agree to the site rules.';
            }

            // Send verification code if all checks passed
            if (empty($errors)) {
                try {
                    $code   = (string) random_int(100000, 999999);
                    $isTemp = ($accountType === 'temp24');
                    $send   = sendVerificationCode($email, $username, $code, $isTemp);

                    if ($send['ok']) {
                        $_SESSION['pending_reg'] = [
                            'username'      => $username,
                            'email'         => $email,
                            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                            'is_temp'       => $isTemp,
                            'code_hash'     => password_hash($code, PASSWORD_DEFAULT),
                            'expires_at'    => time() + 600,
                            'last_sent'     => time(),
                            'attempts'      => 0,
                        ];
                        $_SESSION['reg_rate']['count']++;
                        $notices[] = 'Verification code sent! Check your Gmail/Outlook inbox (and spam folder).';
                    } else {
                        $errors[] = $send['error'];
                    }
                } catch (Throwable $e) {
                    $errors[] = 'An unexpected error occurred while sending the verification code.';
                }
            }
        }
    }
}

$isVerifying = !empty($_SESSION['pending_reg']);
$resendWait  = $isVerifying ? max(0, 60 - (time() - ($_SESSION['pending_reg']['last_sent'] ?? 0))) : 0;
?>
<!DOCTYPE html>
<html lang="en" data-theme="blue">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register - DuskNet</title>
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

        /* Main Layout */
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
        }

        .box-body {
            padding: 10px 12px;
        }

        /* Status Messages */
        .msg-box {
            padding: 7px 9px;
            margin-bottom: 10px;
            font-size: 11px;
            border: 1px solid;
        }

        .msg-error {
            background-color: var(--err-bg);
            border-color: var(--err-border);
            color: var(--err-text);
        }

        .msg-success {
            background-color: var(--ok-bg);
            border-color: var(--ok-border);
            color: var(--ok-text);
        }

        .msg-box ul {
            margin: 3px 0 0 16px;
            padding: 0;
        }

        .msg-info {
            background-color: var(--bg-soft);
            border-color: var(--border-strong);
            color: var(--text);
        }

        /* Honeypot field hidden from humans */
        .hp-field {
            position: absolute;
            left: -9999px;
            width: 1px;
            height: 1px;
            overflow: hidden;
        }

        /* Registration Form */
        .form-row {
            margin-bottom: 9px;
        }

        .form-row label {
            display: block;
            font-weight: bold;
            margin-bottom: 2px;
        }

        .form-row input[type="text"],
        .form-row input[type="email"],
        .form-row input[type="password"],
        .form-row select {
            width: 100%;
            max-width: 320px;
            padding: 4px 6px;
            border: 1px solid var(--input-border);
            background-color: var(--input-bg);
            color: var(--text);
            font-family: inherit;
            font-size: 11px;
        }

        .form-row input:focus,
        .form-row select:focus {
            outline: none;
            border-color: var(--header-top);
        }

        .pass-wrap {
            display: flex;
            align-items: stretch;
            gap: 4px;
            max-width: 320px;
        }

        .pass-wrap input {
            flex: 1;
            min-width: 0;
        }

        .pass-toggle {
            background: linear-gradient(to bottom, var(--btn-top), var(--btn-bot));
            color: var(--btn-text);
            border: 1px solid var(--border-strong);
            padding: 0 7px;
            font-family: inherit;
            font-size: 10px;
            cursor: pointer;
            flex-shrink: 0;
        }

        .pass-toggle:hover {
            filter: brightness(0.96);
        }

        .code-input {
            max-width: 150px !important;
            font-size: 15px !important;
            font-weight: bold;
            letter-spacing: 4px;
            text-align: center;
        }

        .field-hint {
            font-size: 10px;
            color: var(--text-muted);
            margin-top: 2px;
        }

        .field-hint.ok {
            color: var(--ok-text);
            font-weight: bold;
        }

        .field-hint.bad {
            color: var(--err-text);
            font-weight: bold;
        }

        .badge-temp {
            display: inline-block;
            font-size: 9px;
            font-weight: bold;
            padding: 0 4px;
            margin-left: 3px;
            border: 1px solid var(--border-strong);
            background-color: var(--bg-box);
            color: var(--link);
        }

        /* Divider & Disabled Google Button */
        .oauth-divider {
            margin: 12px 0 9px;
            border-top: 1px dotted var(--border);
            padding-top: 9px;
            max-width: 320px;
        }

        .btn-google {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: linear-gradient(to bottom, var(--btn-top), var(--btn-bot));
            color: var(--text-muted);
            border: 1px solid var(--border);
            padding: 4px 11px;
            font-family: inherit;
            font-size: 11px;
            font-weight: bold;
            opacity: 0.62;
            cursor: not-allowed;
        }

        /* Classic Floating Toast Notification */
        .toast-area {
            position: fixed;
            right: 12px;
            bottom: 12px;
            z-index: 50;
            max-width: 290px;
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .toast-item {
            background-color: var(--bg-box);
            border: 1px solid var(--border-strong);
            box-shadow: 2px 2px 0 rgba(0, 0, 0, 0.16);
            font-size: 11px;
        }

        .toast-head {
            background: linear-gradient(to bottom, var(--title-top), var(--title-bot));
            color: #ffffff;
            padding: 3px 7px;
            font-size: 10px;
            font-weight: bold;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .toast-close {
            background: none;
            border: none;
            color: #ffffff;
            font-weight: bold;
            cursor: pointer;
            padding: 0 2px;
        }

        .toast-body {
            padding: 6px 8px;
            color: var(--text);
        }

        .btn {
            display: inline-block;
            background: linear-gradient(to bottom, var(--btn-top), var(--btn-bot));
            color: var(--btn-text);
            border: 1px solid var(--border-strong);
            box-shadow: inset 1px 1px 0 rgba(255, 255, 255, 0.5);
            padding: 3px 12px;
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

        .rules-list {
            margin: 0;
            padding-left: 16px;
            color: var(--text);
        }

        .rules-list li {
            margin-bottom: 5px;
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
            <div class="header-right">
                <div>
                    <a href="login.php">Login</a><span class="sep">|</span><a href="register.php">Sign Up</a><span class="sep">|</span><a href="help.php">Help</a>
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
                <a href="register.php">Register</a>
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

            <!-- Left Column: Registration Form -->
            <div class="main-col">
                <section class="box">
                    <div class="box-title">
                        <?= $isVerifying ? 'Verify Email Address' : 'Create New Account' ?>
                    </div>
                    <div class="box-body">

                        <?php if (!empty($errors)): ?>
                            <div class="msg-box msg-error">
                                <strong>Please fix the following:</strong>
                                <ul>
                                    <?php foreach ($errors as $err): ?>
                                        <li><?= htmlspecialchars($err, ENT_QUOTES, 'UTF-8') ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($notices)): ?>
                            <div class="msg-box msg-info">
                                <?php foreach ($notices as $note): ?>
                                    <div><?= htmlspecialchars($note, ENT_QUOTES, 'UTF-8') ?></div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>

                        <?php if ($success !== ''): ?>
                            <div class="msg-box msg-success">
                                <?= $success ?> <a href="login.php"><strong>Go to Login &raquo;</strong></a>
                            </div>
                        <?php endif; ?>

                        <?php if ($isVerifying): ?>
                            <!-- STEP 2: Enter 6-Digit Email Code -->
                            <p style="margin-top: 0;">
                                We sent a 6-digit verification code to
                                <strong><?= htmlspecialchars($_SESSION['pending_reg']['email'], ENT_QUOTES, 'UTF-8') ?></strong>.
                                <?php if (!empty($_SESSION['pending_reg']['is_temp'])): ?>
                                    <span class="badge-temp">24h Temp Account</span>
                                <?php endif; ?>
                            </p>

                            <form action="register.php" method="post" autocomplete="off">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="action" value="verify_code">
                                <div class="hp-field"><input type="text" name="website" tabindex="-1" autocomplete="off"></div>

                                <div class="form-row">
                                    <label for="verify_code">Verification Code (6 digits)</label>
                                    <input type="text" id="verify_code" name="verify_code" class="code-input" maxlength="6" inputmode="numeric" placeholder="000000" required autofocus>
                                    <div class="field-hint">Code expires in 10 minutes. Max 5 attempts.</div>
                                </div>

                                <div style="margin-top: 10px; display: flex; flex-wrap: wrap; gap: 6px;">
                                    <button type="submit" class="btn btn-primary">Verify &amp; Create Account</button>
                                </div>
                            </form>

                            <div style="margin-top: 10px; padding-top: 8px; border-top: 1px dotted var(--border); display: flex; flex-wrap: wrap; gap: 6px;">
                                <form action="register.php" method="post" style="margin: 0;">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="action" value="resend_code">
                                    <button type="submit" class="btn" id="resendBtn" <?= $resendWait > 0 ? 'disabled' : '' ?>>
                                        <?= $resendWait > 0 ? "Resend Code ({$resendWait}s)" : 'Resend Code' ?>
                                    </button>
                                </form>

                                <form action="register.php" method="post" style="margin: 0;">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="action" value="cancel_verify">
                                    <button type="submit" class="btn">Change Email / Cancel</button>
                                </form>
                            </div>

                        <?php else: ?>
                            <!-- STEP 1: Registration Details -->
                            <form action="register.php" method="post" autocomplete="off" id="registerForm">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="action" value="start_register">
                                <!-- Anti-bot honeypot -->
                                <div class="hp-field">
                                    <label for="website">Website</label>
                                    <input type="text" id="website" name="website" tabindex="-1" autocomplete="off">
                                </div>

                                <div class="form-row">
                                    <label for="username">Username</label>
                                    <input type="text" id="username" name="username" maxlength="20" value="<?= htmlspecialchars($username, ENT_QUOTES, 'UTF-8') ?>" required>
                                    <div class="field-hint">3-20 characters. Letters, numbers, and underscores only.</div>
                                </div>

                                <div class="form-row">
                                    <label for="email">Email Address (Gmail or Outlook only)</label>
                                    <input type="email" id="email" name="email" placeholder="example@gmail.com" value="<?= htmlspecialchars($email, ENT_QUOTES, 'UTF-8') ?>" required>
                                    <div class="field-hint" id="emailHint">Only @gmail.com and @outlook.com addresses are accepted.</div>
                                </div>

                                <div class="form-row">
                                    <label for="password">Password</label>
                                    <div class="pass-wrap">
                                        <input type="password" id="password" name="password" minlength="8" required>
                                        <button type="button" class="pass-toggle" data-toggle-pass="password">Show</button>
                                    </div>
                                    <div class="field-hint">Minimum 8 characters, with at least 1 uppercase letter, 1 lowercase letter, and 1 number.</div>
                                </div>

                                <div class="form-row">
                                    <label for="confirm_password">Confirm Password</label>
                                    <div class="pass-wrap">
                                        <input type="password" id="confirm_password" name="confirm_password" minlength="8" required>
                                        <button type="button" class="pass-toggle" data-toggle-pass="confirm_password">Show</button>
                                    </div>
                                </div>

                                <div class="form-row">
                                    <label for="account_type">Account Type</label>
                                    <select id="account_type" name="account_type">
                                        <option value="permanent" <?= $accountType === 'permanent' ? 'selected' : '' ?>>Standard (Permanent)</option>
                                        <option value="temp24" <?= $accountType === 'temp24' ? 'selected' : '' ?>>24-Hour Temporary (Auto-deletes after 24h)</option>
                                    </select>
                                </div>

                                <div class="form-row" style="margin-top: 10px;">
                                    <label style="font-weight: normal;">
                                        <input type="checkbox" name="agree_rules" required>
                                        I agree to the <a href="rules.php">DuskNet Rules</a>.
                                    </label>
                                </div>

                                <div style="margin-top: 12px;">
                                    <button type="submit" class="btn btn-primary">Send Verification Code</button>
                                    <a href="index.php" class="btn">Cancel</a>
                                </div>

                                <!-- Disabled Google Sign-Up Option (Coming Soon) -->
                                <div class="oauth-divider">
                                    <button type="button" class="btn-google" disabled title="Google Sign-Up is currently disabled">
                                        <svg viewBox="0 0 18 18" width="13" height="13" aria-hidden="true">
                                            <path fill="#4285F4" d="M17.64 9.2c0-.64-.06-1.25-.16-1.84H9v3.48h4.84a4.14 4.14 0 0 1-1.8 2.72v2.26h2.92c1.7-1.57 2.68-3.88 2.68-6.62z"/>
                                            <path fill="#34A853" d="M9 18c2.43 0 4.47-.8 5.96-2.18l-2.92-2.26c-.8.54-1.83.86-3.04.86-2.34 0-4.33-1.58-5.04-3.71H.96v2.33A9 9 0 0 0 9 18z"/>
                                            <path fill="#FBBC05" d="M3.96 10.71A5.41 5.41 0 0 1 3.68 9c0-.59.1-1.17.28-1.71V4.96H.96A9 9 0 0 0 0 9c0 1.45.35 2.82.96 4.04l3-2.33z"/>
                                            <path fill="#EA4335" d="M9 3.58c1.32 0 2.5.45 3.44 1.35l2.58-2.58C13.46.89 11.43 0 9 0A9 9 0 0 0 .96 4.96l3 2.33C4.67 5.16 6.66 3.58 9 3.58z"/>
                                        </svg>
                                        Sign up with Google (Coming Soon)
                                    </button>
                                </div>
                            </form>
                        <?php endif; ?>

                    </div>
                </section>
            </div>

            <!-- Right Column: Info Sidebar -->
            <aside class="side-col">
                <section class="box">
                    <div class="box-title">Already Registered?</div>
                    <div class="box-body">
                        <p style="margin-top: 0; margin-bottom: 8px;">
                            If you already have a DuskNet account, sign in directly.
                        </p>
                        <a href="login.php" class="btn">Member Login</a>
                    </div>
                </section>

                <section class="box">
                    <div class="box-title">Account Security</div>
                    <div class="box-body">
                        <ul class="rules-list">
                            <li>Only <strong>Gmail</strong> and <strong>Outlook</strong> emails are allowed to prevent spam.</li>
                            <li>A 6-digit email verification code is required before activation.</li>
                            <li><strong>24h Temporary Accounts</strong> vanish automatically after 24 hours.</li>
                        </ul>
                    </div>
                </section>

                <section class="box">
                    <div class="box-title">Basic Rules</div>
                    <div class="box-body">
                        <ul class="rules-list">
                            <li>Be respectful in public rooms.</li>
                            <li>No spam, flooding, or bots.</li>
                            <li>Do not share personal info.</li>
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

    <!-- Notification Toast Area -->
    <div class="toast-area" id="toastArea"></div>

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

        /* Classic Notification Toast Helper */
        function showToast(title, message) {
            const area = document.getElementById('toastArea');
            if (!area) return;
            const item = document.createElement('div');
            item.className = 'toast-item';
            item.innerHTML =
                '<div class="toast-head">' +
                    '<span></span>' +
                    '<button type="button" class="toast-close" aria-label="Close">&times;</button>' +
                '</div>' +
                '<div class="toast-body"></div>';
            item.querySelector('.toast-head span').textContent = title;
            item.querySelector('.toast-body').textContent = message;
            item.querySelector('.toast-close').addEventListener('click', () => item.remove());
            area.appendChild(item);
            setTimeout(() => { if (item.parentNode) item.remove(); }, 6000);
        }

        /* Show server-side notifications as classic toasts too */
        <?php if ($success !== ''): ?>
            showToast('Success', <?= json_encode(strip_tags($success)) ?>);
        <?php elseif (!empty($notices)): ?>
            showToast('DuskNet Notice', <?= json_encode($notices[0]) ?>);
        <?php elseif (!empty($errors)): ?>
            showToast('Registration Error', <?= json_encode($errors[0]) ?>);
        <?php endif; ?>

        /* Live Gmail / Outlook Email Check */
        const emailInput = document.getElementById('email');
        const emailHint = document.getElementById('emailHint');
        const allowedDomains = ['gmail.com', 'outlook.com', 'outlook.com.tr'];

        if (emailInput && emailHint) {
            emailInput.addEventListener('input', () => {
                const val = emailInput.value.trim().toLowerCase();
                if (!val.includes('@')) {
                    emailHint.className = 'field-hint';
                    emailHint.textContent = 'Only @gmail.com and @outlook.com addresses are accepted.';
                    return;
                }
                const domain = val.split('@')[1] || '';
                if (allowedDomains.includes(domain)) {
                    emailHint.className = 'field-hint ok';
                    emailHint.textContent = 'Valid provider (' + domain + ').';
                } else {
                    emailHint.className = 'field-hint bad';
                    emailHint.textContent = 'Unsupported email domain. Use @gmail.com or @outlook.com.';
                }
            });
        }

        /* Resend Code Cooldown Countdown */
        const resendBtn = document.getElementById('resendBtn');
        let waitSecs = <?= (int) $resendWait ?>;
        if (resendBtn && waitSecs > 0) {
            const cd = setInterval(() => {
                waitSecs--;
                if (waitSecs <= 0) {
                    clearInterval(cd);
                    resendBtn.disabled = false;
                    resendBtn.textContent = 'Resend Code';
                } else {
                    resendBtn.textContent = 'Resend Code (' + waitSecs + 's)';
                }
            }, 1000);
        }

        /* Show / Hide Password Toggle */
        document.querySelectorAll('[data-toggle-pass]').forEach(btn => {
            btn.addEventListener('click', () => {
                const input = document.getElementById(btn.dataset.togglePass);
                if (!input) return;
                const isHidden = input.type === 'password';
                input.type = isHidden ? 'text' : 'password';
                btn.textContent = isHidden ? 'Hide' : 'Show';
            });
        });
    </script>

</body>
</html>