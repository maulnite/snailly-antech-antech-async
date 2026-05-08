<?php
/**
 * Snailly Local Web
 * Original: Nextron/Electron + React/TypeScript + Python proxy.
 * Converted to: PHP + HTML + CSS + JavaScript.
 */
if (
    empty($_SERVER['HTTPS']) || $_SERVER['HTTPS'] === 'off'
) {
    $httpsUrl = 'https://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
    header('Location: ' . $httpsUrl, true, 302);
    exit;
}

$page = $_GET['page'] ?? 'home';
$allowedPages = ['home','login','register','login-child','child-dashboard','child-logs','dashboard','children','log-activity','rules','access-requests','schedule','report','streak-calendar','setting','about','blocked'];
if (!in_array($page, $allowedPages, true)) {
    $page = 'home';
}

$config = [
    'apiBase' => 'local-php-backend',
    'appName' => 'Snailly Kids',
    'version' => '1.0-web',
];
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($config['appName']) ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
    <script>
        window.SNAILLY_CONFIG = <?= json_encode($config, JSON_UNESCAPED_SLASHES) ?>;
        window.SNAILLY_INITIAL_PAGE = <?= json_encode($page) ?>;
    </script>
</head>
<body>
    <div id="toast" class="toast" aria-live="polite"></div>

    <main id="app" class="app">
        <section data-page="home" class="page page-center auth-bg">
            <div class="home-card glass-card">
                <img class="logo big-logo" src="assets/img/logo.png" alt="Snailly logo">
                <div class="page-heading center-text">
                    <h1>Let me know who are you?</h1>
                    <p>Choose one of those role and click continue to access.</p>
                </div>
                <div class="role-grid">
                    <a class="role-card" href="?page=login" data-link="login">
                        <span class="role-icon">👨‍👩‍👧</span>
                        <span>Parent</span>
                    </a>
                    <a class="role-card" href="?page=login-child" data-link="login-child">
                        <span class="role-icon">🧒</span>
                        <span>Kids</span>
                    </a>
                </div>
            </div>
        </section>

        <section data-page="login" class="page page-center auth-bg">
            <form id="loginForm" class="auth-card glass-card">
                <img class="logo" src="assets/img/logo.png" alt="Snailly logo">
                <h1>Login</h1>
                <label>Email
                    <input name="email" type="email" placeholder="Type your email here" required autocomplete="email">
                </label>
                <label>Password
                    <input name="password" type="password" placeholder="Type your password here" required autocomplete="current-password">
                </label>
                <button class="btn primary" type="submit">Login</button>
                <p class="small-text">Don't have account? <a href="?page=register" data-link="register">Register</a></p>
            </form>
        </section>

        <section data-page="register" class="page page-center auth-bg">
            <form id="registerForm" class="auth-card glass-card">
                <img class="logo" src="assets/img/logo.png" alt="Snailly logo">
                <h1>Registration</h1>
                <label>Name
                    <input name="name" type="text" placeholder="Type your name here" required>
                </label>
                <label>Email
                    <input name="email" type="email" placeholder="Type your email here" required autocomplete="email">
                </label>
                <label>Password
                    <input name="password" type="password" placeholder="Min. 8 chars, letters and numbers" required autocomplete="new-password">
                    <small class="muted">Minimal 8 karakter, wajib ada huruf dan angka.</small>
                </label>
                <label>Confirm Password
                    <input name="confirmPassword" type="password" placeholder="Type your confirm password here" required autocomplete="new-password">
                </label>
                <button class="btn primary" type="submit">Register</button>
                <p class="small-text">Have already account? <a href="?page=login" data-link="login">Login</a></p>
            </form>
        </section>

        <section data-page="login-child" class="page page-center auth-bg">
            <div class="glass-card child-login-card">
                <img class="logo big-logo" src="assets/img/logo.png" alt="Snailly logo">
                <div id="childLoginContent"></div>
            </div>
        </section>



        <section data-page="child-dashboard" class="page child-dashboard-page">
            <div class="child-dashboard-shell">
                <header class="child-hero">
                    <div class="child-hero-copy">
                        <p class="eyebrow">Kids Safe Space</p>
                        <h1 id="childDashboardGreeting">Hi, Explorer! 👋</h1>
                        <p>Belajar, bermain, dan browsing dengan lebih aman. Kalau ada website yang terasa aneh, jangan klik sembarang link ya.</p>
                        <div class="child-hero-actions">
                            <a class="btn child-primary" href="https://kids.nationalgeographic.com" target="_blank" rel="noopener">Explore Learning</a>
                            <a class="btn child-secondary" href="https://scratch.mit.edu/projects/editor/" target="_blank" rel="noopener">Open Scratch</a>
                        </div>
                    </div>
                    <div class="child-mascot-card">
                        <div class="mascot-orbit"><span>🛡️</span><span>🌐</span><span>⭐</span></div>
                        <div class="mascot-face">🧒</div>
                        <strong>Safe Mode ON</strong>
                        <small>Snailly is watching risky URLs.</small>
                    </div>
                </header>

                <div id="childDashboardContent" class="child-dashboard-content"></div>

                <footer class="child-footer-actions">
                    <a class="btn child-secondary" href="?page=login-child" data-link="login-child">Switch Child</a>
                    <button class="btn child-danger" type="button" id="childLogoutButton">Exit Kids Mode</button>
                </footer>
            </div>
        </section>

        <section data-page="child-logs" class="page child-dashboard-page">
            <div class="child-dashboard-shell child-logs-shell">
                <header class="child-logs-hero">
                    <div>
                        <p class="eyebrow">Kids Activity</p>
                        <h1 id="childLogsTitle">My Browsing Log</h1>
                        <p class="muted">Lihat ringkasan aktivitas browsing kamu sendiri tanpa masuk ke dashboard parent.</p>
                    </div>
                    <div class="child-hero-actions">
                        <button class="btn child-secondary" type="button" id="childLogsBackButton">Back to Kids Dashboard</button>
                    </div>
                </header>
                <div id="childLogsContent" class="child-dashboard-content"></div>
            </div>
        </section>

        <section data-page="dashboard" class="page dashboard-layout">
            <?php include __DIR__ . '/partials/sidebar.php'; ?>
            <div class="main-content">
                <div class="topbar">
                    <div>
                        <h1>Dashboard</h1>
                        <p class="muted">Overview of children browsing activity.</p>
                    </div>
                    <select id="globalChildFilter" class="input-compact"></select>
                </div>
                <div id="dashboardContent"></div>
            </div>
        </section>

        <section data-page="children" class="page dashboard-layout">
            <?php include __DIR__ . '/partials/sidebar.php'; ?>
            <div class="main-content">
                <div class="topbar">
                    <div>
                        <h1>Children</h1>
                        <p class="muted">Manage children accounts.</p>
                    </div>
                    <button class="btn primary" id="openAddChild">+ Add Child</button>
                </div>
                <div id="childrenContent"></div>
            </div>
        </section>

        <section data-page="log-activity" class="page dashboard-layout">
            <?php include __DIR__ . '/partials/sidebar.php'; ?>
            <div class="main-content">
                <div class="topbar wrap">
                    <div>
                        <h1>Log Activity</h1>
                        <p class="muted">Browse and filter activity logs.</p>
                    </div>
                    <div class="toolbar">
                        <select id="logChildFilter" class="input-compact" aria-label="Filter children name">
                            <option value="ALL">All Children</option>
                        </select>
                        <select id="logStatusFilter" class="input-compact" aria-label="Filter status">
                            <option value="all">All Status</option>
                            <option value="positive">Positive Only</option>
                            <option value="negative">Negative Only</option>
                            <option value="pending">Not Labelled Only</option>
                        </select>
                        <select id="logPeriod" class="input-compact">
                            <option value="daily">Daily</option>
                            <option value="monthly">Monthly</option>
                            <option value="all">All</option>
                        </select>
                        <input id="logDate" class="input-compact" type="date">
                        <input id="logMonth" class="input-compact" type="month">
                        <input id="logSearch" class="input-compact search-input" type="search" placeholder="Search URL / category">
                        <button id="exportLogsButton" class="btn secondary" type="button">Export CSV</button>
                        <button id="clearLogsButton" class="btn danger" type="button">Clear Logs</button>
                    </div>
                </div>
                <div id="logActivityContent"></div>
            </div>
        </section>



        <section data-page="rules" class="page dashboard-layout">
            <?php include __DIR__ . '/partials/sidebar.php'; ?>
            <div class="main-content">
                <div class="topbar wrap">
                    <div>
                        <h1>Parent Rules</h1>
                        <p class="muted">Custom whitelist dan blocklist website sesuai kebutuhan anak.</p>
                    </div>
                </div>
                <div id="rulesContent"></div>
            </div>
        </section>

        <section data-page="access-requests" class="page dashboard-layout">
            <?php include __DIR__ . '/partials/sidebar.php'; ?>
            <div class="main-content">
                <div class="topbar wrap">
                    <div>
                        <h1>Access Requests</h1>
                        <p class="muted">Approve atau deny request anak saat website diblokir.</p>
                    </div>
                    <select id="requestStatusFilter" class="input-compact">
                        <option value="all" selected>All</option>
                        <option value="pending">Pending</option>
                        <option value="approved">Approved</option>
                        <option value="denied">Denied</option>
                    </select>
                </div>
                <div id="requestsContent"></div>
            </div>
        </section>

        <section data-page="schedule" class="page dashboard-layout">
            <?php include __DIR__ . '/partials/sidebar.php'; ?>
            <div class="main-content">
                <div class="topbar wrap">
                    <div>
                        <h1>Internet Schedule</h1>
                        <p class="muted">Atur jam browsing anak. Extension akan block website di luar jadwal.</p>
                    </div>
                </div>
                <div id="scheduleContent"></div>
            </div>
        </section>

        <section data-page="report" class="page dashboard-layout">
            <?php include __DIR__ . '/partials/sidebar.php'; ?>
            <div class="main-content">
                <div class="topbar wrap">
                    <div>
                        <h1>Daily / Weekly Report</h1>
                        <p class="muted">Ringkasan aktivitas anak untuk bahan evaluasi orang tua.</p>
                    </div>
                    <div class="toolbar">
                        <select id="reportChildFilter" class="input-compact"><option value="ALL">All Children</option></select>
                        <select id="reportPeriod" class="input-compact">
                            <option value="daily">Daily</option>
                            <option value="weekly">Weekly</option>
                            <option value="monthly">Monthly</option>
                            <option value="all">All</option>
                        </select>
                        <input id="reportDate" class="input-compact" type="date">
                        <button id="printReportButton" class="btn secondary" type="button">Print / Save PDF</button>
                    </div>
                </div>
                <div id="reportContent"></div>
            </div>
        </section>

        <section data-page="setting" class="page dashboard-layout">
            <?php include __DIR__ . '/partials/sidebar.php'; ?>
            <div class="main-content">
                <div class="topbar">
                    <div>
                        <h1>Setting</h1>
                        <p class="muted">Update parent profile. Password boleh dikosongkan kalau tidak ingin diganti.</p>
                    </div>
                </div>
                <form id="settingForm" class="panel form-panel setting-card">
                    <div class="form-section-title">
                        <strong>Parent Profile</strong>
                        <span>Nama dan email akun parent.</span>
                    </div>
                    <label>Name
                        <input name="name" type="text" placeholder="Type your name here" required>
                    </label>
                    <label>Email
                        <input name="email" type="email" placeholder="Type your email here" required autocomplete="email">
                    </label>
                    <div class="form-section-title with-border">
                        <strong>Change Password</strong>
                        <span>Isi bagian ini hanya kalau mau ganti password.</span>
                    </div>
                    <label>Old Password
                        <input name="oldPassword" type="password" placeholder="Required only when changing password" autocomplete="current-password">
                    </label>
                    <label>New Password
                        <input name="newPassword" type="password" placeholder="Min. 8 chars, letters and numbers" autocomplete="new-password">
                    </label>
                    <label>Confirm Password
                        <input name="confirmPassword" type="password" placeholder="Confirm new password" autocomplete="new-password">
                    </label>
                    <button class="btn primary" type="submit">Save Changes</button>
                </form>
            </div>
        </section>

        <section data-page="about" class="page dashboard-layout">
            <?php include __DIR__ . '/partials/sidebar.php'; ?>
            <div class="main-content about-content">
                <div class="panel about-panel">
                    <img class="logo big-logo" src="assets/img/logo.png" alt="Snailly logo">
                    <p><strong>Snailly: Safe browsing for the children</strong><br>version 1.0 local-backend &copy;<br>Local PHP backend version.</p>
                    <p>Snailly is an application for parents to control and supervise their children's activities on the internet, where children can explore the internet safely and parents will not worry about the dangers of the internet.</p>
                    <p class="notice"><strong>Catatan konversi:</strong> backend utama berjalan lokal memakai PHP + JSON storage. Untuk real URL tracking, versi ini menambahkan Chrome/Edge extension di folder <code>extension/</code> yang mengirim URL ke <code>api/track.php</code>.</p>
                </div>
            </div>
        </section>


        <section data-page="streak-calendar" class="page streak-calendar-page">
            <div class="streak-shell">
                <div class="streak-topbar">
                    <div>
                        <p class="eyebrow">Learning Streak Calendar</p>
                        <h1 id="streakCalendarTitle">Browsing Streak</h1>
                        <p class="muted">Hari yang aman akan ditandai hijau. Hari yang berisiko akan ditandai merah supaya gampang dievaluasi.</p>
                    </div>
                    <div class="toolbar">
                        <select id="streakChildFilter" class="input-compact"></select>
                        <input id="streakMonth" class="input-compact" type="month">
                        <button class="btn secondary" type="button" id="streakBackButton">Back</button>
                    </div>
                </div>
                <div id="streakCalendarContent"></div>
            </div>
        </section>

        <section data-page="blocked" class="page page-center danger-bg">
            <div class="blocked-card">
                <div class="blocked-icon">🔒</div>
                <h1>Website Blocked</h1>
                <p>This website is listed as restricted by Snailly.</p>
                <p id="blockedUrl" class="url-box"></p>
                <p id="blockedReason" class="muted"></p>
                <div class="modal-actions centered-actions">
                    <button class="btn primary" id="requestAccessButton" type="button">Request Access</button>
                    <a class="btn secondary" href="https://scratch.mit.edu/projects/editor/" target="_blank" rel="noopener">Open Scratch Instead</a>
                    <a class="btn ghost" href="?page=home" data-link="home">Back to Home</a>
                </div>
            </div>
        </section>
    </main>

    <dialog id="modal" class="modal">
        <form method="dialog" class="modal-card">
            <button class="modal-close" value="cancel">×</button>
            <h2 id="modalTitle">Modal</h2>
            <div id="modalBody"></div>
            <div id="modalActions" class="modal-actions"></div>
        </form>
    </dialog>

    <script src="assets/js/app.js"></script>
</body>
</html>
