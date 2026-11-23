<?php
session_start();
require_once __DIR__ . '/db.php';

// Generate CSRF token for form security
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Automatically purge expired 24-hour temporary accounts before login checks
try {
    $pdo->exec('DELETE FROM users WHERE expires_at IS NOT NULL AND expires_at <= NOW()');
} catch (Throwable $e) {
    // Ignore if expires_at column does not exist yet
}

// Session-based brute-force protection (max 5 failed attempts per 10 minutes)
if (!isset($_SESSION['login_rate'])) {
    $_SESSION['login_rate'] = ['fails' => 0, 'lock_until' => 0, 'window_start' => time()];
}
if (time() - $_SESSION['login_rate']['window_start'] > 600 && time() > $_SESSION['login_rate']['lock_until']) {
    $_SESSION['login_rate'] = ['fails' => 0, 'lock_until' => 0, 'window_start' => time()];
}

$error = '';
$rememberedUser = $_COOKIE['dusknet_user'] ?? '';
$loginInput = $rememberedUser;
$rememberChecked = $rememberedUser !== '';
$isLoggedIn = !empty($_SESSION['user_id']);
$lockRemaining = max(0, ($_SESSION['login_rate']['lock_until'] ?? 0) - time());

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$isLoggedIn) {
    $token = $_POST['csrf_token'] ?? '';
    $loginInput = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $rememberChecked = isset($_POST['remember']);

    if (!hash_equals($_SESSION['csrf_token'], $token)) {
        $error = 'Security check failed (invalid token). Please refresh and try again.';
    } elseif (!empty($_POST['website'])) {
        // Honeypot anti-bot field must remain empty
        $error = 'Automated submission blocked.';
    } elseif ($lockRemaining > 0) {
        $mins = (int) ceil($lockRemaining / 60);
        $error = "Too many failed login attempts. Please wait {$mins} minute(s) and try again.";
    } elseif ($loginInput === '' || $password === '') {
        $error = 'Please enter both username/email and password.';
    } else {
        try {
            // Allow login with either username or mail
            $stmt = $pdo->prepare(
                'SELECT id, username, password_hash, role FROM users WHERE username = ? OR mail = ? LIMIT 1'
            );
            $stmt->execute([$loginInput, $loginInput]);
            $user = $stmt->fetch();

            if ($user && password_verify($password, $user['password_hash'])) {
                // Reset failed attempt counter on successful login
                $_SESSION['login_rate'] = ['fails' => 0, 'lock_until' => 0, 'window_start' => time()];

                // Update last known IP address and mark user online immediately
                $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
                try {
                    $updateUser = $pdo->prepare('UPDATE users SET ip = ?, last_seen = NOW() WHERE id = ?');
                    $updateUser->execute([$ip, $user['id']]);
                } catch (Throwable $e) {
                    $updateIp = $pdo->prepare('UPDATE users SET ip = ? WHERE id = ?');
                    $updateIp->execute([$ip, $user['id']]);
                }

                // Save or clear remembered username cookie (30 days)
                if ($rememberChecked) {
                    setcookie('dusknet_user', $user['username'], [
                        'expires'  => time() + (86400 * 30),
                        'path'     => '/',
                        'httponly' => true,
                        'samesite' => 'Lax',
                    ]);
                } else {
                    setcookie('dusknet_user', '', [
                        'expires'  => time() - 3600,
                        'path'     => '/',
                        'httponly' => true,
                        'samesite' => 'Lax',
                    ]);
                }

                // Prevent session fixation, rotate CSRF token, and store user session
                session_regenerate_id(true);
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                $_SESSION['user_id'] = (int) $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['role'] = $user['role'];

                header('Location: index.php');
                exit;
            } else {
                // Track failed attempts and apply 5-minute cooldown after 5 failures
                $_SESSION['login_rate']['fails']++;
                if ($_SESSION['login_rate']['fails'] >= 5) {
                    $_SESSION['login_rate']['lock_until'] = time() + 300;
                    $lockRemaining = 300;
                    $error = 'Too many failed login attempts. Login is temporarily locked for 5 minutes.';
                } else {
                    $left = 5 - $_SESSION['login_rate']['fails'];
                    $error = "Invalid username/email or password. ({$left} attempt(s) remaining)";
                }
            }
        } catch (Throwable $e) {
            $error = 'Unable to sign in right now. Please try again later.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="blue">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - DuskNet</title>
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

        /* Honeypot field hidden from humans */
        .hp-field {
            position: absolute;
            left: -9999px;
            width: 1px;
            height: 1px;
            overflow: hidden;
        }

        /* Login Form */
        .form-row {
            margin-bottom: 9px;
        }

        .form-row label {
            display: block;
            font-weight: bold;
            margin-bottom: 2px;
        }

        .form-row input[type="text"],
        .form-row input[type="password"] {
            width: 100%;
            max-width: 310px;
            padding: 4px 6px;
            border: 1px solid var(--input-border);
            background-color: var(--input-bg);
            color: var(--text);
            font-family: inherit;
            font-size: 11px;
        }

        .form-row input:focus {
            outline: none;
            border-color: var(--header-top);
        }

        .pass-wrap {
            display: flex;
            align-items: stretch;
            gap: 4px;
            max-width: 310px;
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

        .field-hint {
            font-size: 10px;
            color: var(--text-muted);
            margin-top: 2px;
        }

        .oauth-divider {
            margin: 12px 0 9px;
            border-top: 1px dotted var(--border);
            padding-top: 9px;
            max-width: 310px;
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

        .rules-list {
            margin: 0;
            padding-left: 16px;
            color: var(--text);
        }

        .rules-list li {
            margin-bottom: 5px;
        }

        .form-row label {
            display: block;
            font-weight: bold;
            margin-bottom: 2px;
        }

        .form-row input[type="text"],
        .form-row input[type="password"] {
            width: 100%;
            max-width: 310px;
            padding: 4px 6px;
            border: 1px solid var(--input-border);
            background-color: var(--input-bg);
            color: var(--text);
            font-family: inherit;
            font-size: 11px;
        }

        .form-row input:focus {
            outline: none;
            border-color: var(--header-top);
        }

        .field-hint {
            font-size: 10px;
            color: var(--text-muted);
            margin-top: 2px;
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
                    <?php if ($isLoggedIn): ?>
                        <strong><?= htmlspecialchars($_SESSION['username'], ENT_QUOTES, 'UTF-8') ?></strong><span class="sep">|</span><a href="logout.php">Logout</a>
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

            <!-- Left Column: Login Form -->
            <div class="main-col">
                <section class="box">
                    <div class="box-title">Member Login</div>
                    <div class="box-body">

                        <?php if ($isLoggedIn): ?>
                            <div class="msg-box msg-success">
                                You are already signed in as
                                <strong><?= htmlspecialchars($_SESSION['username'], ENT_QUOTES, 'UTF-8') ?></strong>
                                (<?= htmlspecialchars($_SESSION['role'], ENT_QUOTES, 'UTF-8') ?>).
                            </div>
                            <a href="index.php" class="btn btn-primary">Go to Home</a>
                            <a href="logout.php" class="btn">Logout</a>
                        <?php else: ?>

                            <?php if ($error !== ''): ?>
                                <div class="msg-box msg-error">
                                    <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
                                </div>
                            <?php endif; ?>

                            <form action="login.php" method="post">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                                <!-- Anti-bot honeypot -->
                                <div class="hp-field">
                                    <label for="website">Website</label>
                                    <input type="text" id="website" name="website" tabindex="-1" autocomplete="off">
                                </div>

                                <div class="form-row">
                                    <label for="username">Username or Email</label>
                                    <input type="text" id="username" name="username" value="<?= htmlspecialchars($loginInput, ENT_QUOTES, 'UTF-8') ?>" required>
                                </div>

                                <div class="form-row">
                                    <label for="password">Password</label>
                                    <div class="pass-wrap">
                                        <input type="password" id="password" name="password" required>
                                        <button type="button" class="pass-toggle" data-toggle-pass="password">Show</button>
                                    </div>
                                </div>

                                <div class="form-row">
                                    <label style="font-weight: normal;">
                                        <input type="checkbox" name="remember" <?= $rememberChecked ? 'checked' : '' ?>>
                                        Remember me
                                    </label>
                                </div>

                                <div style="margin-top: 12px;">
                                    <button type="submit" class="btn btn-primary" <?= $lockRemaining > 0 ? 'disabled' : '' ?>>Login</button>
                                    <a href="index.php" class="btn">Cancel</a>
                                </div>

                                <div class="field-hint" style="margin-top: 10px;">
                                    <a href="forgot.php">Forgot your password?</a>
                                </div>

                                <!-- Disabled Google Sign-In Option (Coming Soon) -->
                                <div class="oauth-divider">
                                    <button type="button" class="btn-google" disabled title="Google Sign-In is currently disabled">
                                        <svg viewBox="0 0 18 18" width="13" height="13" aria-hidden="true">
                                            <path fill="#4285F4" d="M17.64 9.2c0-.64-.06-1.25-.16-1.84H9v3.48h4.84a4.14 4.14 0 0 1-1.8 2.72v2.26h2.92c1.7-1.57 2.68-3.88 2.68-6.62z"/>
                                            <path fill="#34A853" d="M9 18c2.43 0 4.47-.8 5.96-2.18l-2.92-2.26c-.8.54-1.83.86-3.04.86-2.34 0-4.33-1.58-5.04-3.71H.96v2.33A9 9 0 0 0 9 18z"/>
                                            <path fill="#FBBC05" d="M3.96 10.71A5.41 5.41 0 0 1 3.68 9c0-.59.1-1.17.28-1.71V4.96H.96A9 9 0 0 0 0 9c0 1.45.35 2.82.96 4.04l3-2.33z"/>
                                            <path fill="#EA4335" d="M9 3.58c1.32 0 2.5.45 3.44 1.35l2.58-2.58C13.46.89 11.43 0 9 0A9 9 0 0 0 .96 4.96l3 2.33C4.67 5.16 6.66 3.58 9 3.58z"/>
                                        </svg>
                                        Sign in with Google (Coming Soon)
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
                    <div class="box-title">New to DuskNet?</div>
                    <div class="box-body">
                        <p style="margin-top: 0; margin-bottom: 8px;">
                            Need an account? Register to reserve your nickname and join public chat rooms.
                        </p>
                        <a href="register.php" class="btn">Create Account</a>
                    </div>
                </section>

                <section class="box">
                    <div class="box-title">Login Security</div>
                    <div class="box-body">
                        <ul class="rules-list">
                            <li>Account locks for 5 minutes after 5 failed login attempts.</li>
                            <li>Expired <strong>24h Temporary Accounts</strong> are automatically removed.</li>
                            <li>Always log out on shared devices.</li>
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

        <?php if ($error !== ''): ?>
            showToast('Login Error', <?= json_encode($error) ?>);
        <?php endif; ?>

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