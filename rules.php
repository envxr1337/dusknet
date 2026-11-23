<?php
session_start();
require_once __DIR__ . '/db.php';

$isLoggedIn = !empty($_SESSION['user_id']);

/**
 * Renders the blue admin checkmark (with "Admin" hover tooltip)
 * and the Official Main Account tag if the username is "dusknet".
 */
function renderUserBadges(string $username, string $role = 'user'): string
{
    $html = '';

    if (strtolower(trim($role)) === 'admin') {
        $html .= '<span class="admin-tick" data-tip="Admin" aria-label="Admin">'
            . '<svg viewBox="0 0 16 16" width="12" height="12" aria-hidden="true">'
            . '<circle cx="8" cy="8" r="7.5" fill="#1d74e6"/>'
            . '<path d="M4.6 8.3l2.2 2.2 4.6-4.8" fill="none" stroke="#ffffff" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>'
            . '</svg>'
            . '</span>';
    }

    if (strtolower(trim($username)) === 'dusknet') {
        $html .= '<span class="official-badge" title="Official Main Account">Official Account</span>';
    }

    return $html;
}

// Keep user's online activity and role synced if logged in
if ($isLoggedIn) {
    try {
        $touch = $pdo->prepare('UPDATE users SET last_seen = NOW() WHERE id = ?');
        $touch->execute([$_SESSION['user_id']]);

        $meStmt = $pdo->prepare('SELECT username, role FROM users WHERE id = ? LIMIT 1');
        $meStmt->execute([$_SESSION['user_id']]);
        $me = $meStmt->fetch();
        if ($me) {
            $_SESSION['username'] = $me['username'];
            $_SESSION['role'] = $me['role'];
        }
    } catch (Throwable $e) {
        // Keep page functional even if DB query fails
    }
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="blue">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rules - DuskNet</title>
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
            line-height: 1.5;
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

        /* Badges */
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

        /* Rules Typography */
        .rule-section {
            padding-bottom: 9px;
            margin-bottom: 9px;
            border-bottom: 1px dotted var(--border);
        }

        .rule-section:last-child {
            padding-bottom: 0;
            margin-bottom: 0;
            border-bottom: none;
        }

        .rule-section h3 {
            margin: 0 0 4px 0;
            font-size: 12px;
            color: var(--link);
        }

        .rule-section ul {
            margin: 0;
            padding-left: 18px;
        }

        .rule-section li {
            margin-bottom: 4px;
        }

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
            text-align: center;
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

        .side-actions {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .muted {
            color: var(--text-muted);
            font-size: 10px;
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

        <!-- Main Content -->
        <main class="content">

            <!-- Left Column: Rules Content -->
            <div class="main-col">
                <section class="box">
                    <div class="box-title">DuskNet Community Rules</div>
                    <div class="box-body">
                        <p style="margin-top: 0;">
                            By registering an account or participating in any DuskNet chat room, you agree to follow the rules listed below.
                        </p>

                        <div class="rule-section">
                            <h3>1. General Conduct</h3>
                            <ul>
                                <li>Treat other members with basic respect.</li>
                                <li>Harassment, hate speech, racial slurs, and targeted insults in any language are strictly forbidden.</li>
                                <li>Do not intentionally derail discussions or provoke arguments.</li>
                            </ul>
                        </div>

                        <div class="rule-section">
                            <h3>2. Accounts &amp; Usernames</h3>
                            <ul>
                                <li>Offensive, profane, or misleading usernames are not permitted.</li>
                                <li>Impersonating <strong>DuskNet</strong>, official accounts, administrators, or moderators is strictly prohibited.</li>
                                <li>Accounts require email verification using a valid Gmail or Outlook address.</li>
                                <li><strong>24-Hour Temporary Accounts</strong> are automatically deleted after 24 hours and cannot be recovered.</li>
                            </ul>
                        </div>

                        <div class="rule-section">
                            <h3>3. Chat Room Etiquette</h3>
                            <ul>
                                <li>No flooding, message spamming, excessive caps, or repetitive text walls.</li>
                                <li>Do not post unsolicited advertisements, referral links, or bot scripts.</li>
                                <li>Keep conversations roughly relevant to the room topic (e.g., music in <strong>#music</strong>, games in <strong>#games</strong>).</li>
                            </ul>
                        </div>

                        <div class="rule-section">
                            <h3>4. Privacy &amp; Safety</h3>
                            <ul>
                                <li>Never share another user's private information (doxxing) without consent.</li>
                                <li>Do not post phishing links, malware, or illegal content.</li>
                                <li>Keep your password private; DuskNet staff will never ask for your password.</li>
                            </ul>
                        </div>

                        <div class="rule-section">
                            <h3>5. Moderation</h3>
                            <ul>
                                <li>Administrators and moderators may warn, mute, kick, or ban accounts that violate these rules.</li>
                                <li>Attempting to bypass a ban by creating new accounts (including temporary accounts) will result in a permanent IP ban.</li>
                            </ul>
                        </div>

                    </div>
                </section>
            </div>

            <!-- Right Column: Sidebar -->
            <aside class="side-col">
                <section class="box">
                    <div class="box-title">Quick Links</div>
                    <div class="box-body">
                        <div class="side-actions">
                            <a href="index.php" class="btn">Back to Home</a>
                            <?php if ($isLoggedIn): ?>
                                <a href="account.php" class="btn btn-primary">My Account</a>
                            <?php else: ?>
                                <a href="register.php" class="btn btn-primary">Create Account</a>
                                <a href="login.php" class="btn">Member Login</a>
                            <?php endif; ?>
                        </div>
                    </div>
                </section>

                <section class="box">
                    <div class="box-title">Need Help?</div>
                    <div class="box-body">
                        <p style="margin-top: 0; margin-bottom: 8px;">
                            If you see a user breaking the rules, contact an online Admin or Moderator.
                        </p>
                        <div class="muted">
                            Rules may be updated at any time without prior notice.
                        </div>
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
    </script>

</body>
</html>