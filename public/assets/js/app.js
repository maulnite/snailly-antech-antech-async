(() => {
  const cfg = window.SNAILLY_CONFIG || { apiBase: 'local-php-backend', appName: 'Snailly Kids' };
  const pages = [...document.querySelectorAll('[data-page]')];
  const toastRoot = document.getElementById('toast');
  const modal = document.getElementById('modal');
  const modalTitle = document.getElementById('modalTitle');
  const modalBody = document.getElementById('modalBody');
  const modalActions = document.getElementById('modalActions');

  const state = {
    user: readJSON('snailly_user', null),
    children: readJSON('snailly_children', []),
    selectedChildId: localStorage.getItem('snailly_selected_child_id') || '',
    currentUser: readJSON('snailly_current_user', null),
    kidSession: readJSON('snailly_kid_session', null),
    childStatusTimer: null,
  };

  function readJSON(key, fallback) {
    try { return JSON.parse(localStorage.getItem(key)) ?? fallback; }
    catch (_) { return fallback; }
  }
  function writeJSON(key, value) { localStorage.setItem(key, JSON.stringify(value)); }
  function setUser(user) {
    const safeUser = user ? { ...user } : null;

    if (safeUser?.accessToken) {
      localStorage.setItem('snailly_parent_token', safeUser.accessToken);
    } else if (!safeUser) {
      localStorage.removeItem('snailly_parent_token');
    }

    if (safeUser) delete safeUser.accessToken;

    state.user = safeUser;
    safeUser ? writeJSON('snailly_user', safeUser) : localStorage.removeItem('snailly_user');
  }
  function setChildren(children) { state.children = Array.isArray(children) ? children : []; writeJSON('snailly_children', state.children); renderGlobalChildFilter(); }
  function setSelectedChild(id) { state.selectedChildId = id || ''; localStorage.setItem('snailly_selected_child_id', state.selectedChildId); }
  function setKidSession(child) {
    const safeChild = child ? { ...child } : null;

    state.kidSession = safeChild;

    if (safeChild) {
      writeJSON('snailly_kid_session', safeChild);
    } else {
      localStorage.removeItem('snailly_kid_session');
    }
  }
  function activeChild() {
    const id = state.kidSession?.id || state.selectedChildId || '';
    return (state.children || []).find((child) => child.id === id) || state.kidSession || null;
  }
  function token() {
    return state.user?.accessToken || localStorage.getItem('snailly_parent_token') || '';
  }
  function kidToken() { return state.kidSession?.accessToken || ''; }
  function activeAuthToken() { return token() || kidToken(); }
  function escapeHTML(value) {
    return String(value ?? '').replace(/[&<>'"]/g, (c) => ({ '&':'&amp;', '<':'&lt;', '>':'&gt;', "'":'&#039;', '"':'&quot;' }[c]));
  }
  function normalizeResponse(json) { return json?.data ?? json; }
  function toast(message, type = 'info') {
    const item = document.createElement('div');
    item.className = `toast-item ${type}`;
    item.textContent = message;
    toastRoot.appendChild(item);
    setTimeout(() => item.remove(), 4200);
  }
  function messageFromError(error) {
    if (error?.message) return error.message;
    return 'An error occurred. Please try again.';
  }
  function debounce(fn, wait = 350) {
    let timer = null;
    return (...args) => {
      clearTimeout(timer);
      timer = setTimeout(() => fn(...args), wait);
    };
  }
  function formatDateIndonesia(value) {
    if (!value) return '-';
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return value;
    return new Intl.DateTimeFormat('id-ID', {
      weekday: 'long', day: '2-digit', month: 'long', year: 'numeric',
      hour: '2-digit', minute: '2-digit', timeZone: 'Asia/Jakarta', timeZoneName: 'short'
    }).format(date);
  }

  async function api(path, { method = 'GET', data = null, params = {}, auth = true } = {}) {
    const url = new URL('/api/snailly/proxy', window.location.origin);
    url.searchParams.set('path', path);
    Object.entries(params || {}).forEach(([k, v]) => {
      if (v !== undefined && v !== null && v !== '') url.searchParams.set(k, v);
    });
    const headers = { 'Accept': 'application/json' };
    if (data !== null) headers['Content-Type'] = 'application/json';
    const authValue = activeAuthToken();
    if (auth && authValue) headers['X-Snailly-Authorization'] = `Bearer ${authValue}`;
    const response = await fetch(url.toString(), {
      method,
      headers,
      body: data !== null ? JSON.stringify(data) : null,
      credentials: 'same-origin',
    });
    const text = await response.text();
    let json = {};
    try { json = text ? JSON.parse(text) : {}; }
    catch (_) { json = { message: text || 'Invalid JSON response' }; }
    if (!response.ok || json?.ok === false) {
      const msg = json?.message || json?.error || `Request failed (${response.status})`;
      throw new Error(Array.isArray(msg) ? msg.map((m) => m.message || m).join('\n') : msg);
    }
    return json;
  }

  async function bootstrapSession() {
    try {
      const current = await api('/auth/me', { auth: true });
      const sessionUser = normalizeResponse(current);
      if (!sessionUser?.id) return;
      if (sessionUser.role === 'child') {
        setUser(null);
        setChildren([]);
        setKidSession(sessionUser);
        setSelectedChild(sessionUser.id);
      } else {
        setKidSession(null);
        setUser(sessionUser);
        try { await refreshChildren(); } catch (_) {}
      }
    } catch (_) {
      // No valid session cookie. The normal route guard will show login when needed.
    }
  }

  function go(page, replace = false, extraParams = {}) {
    const url = new URL(window.location.href);
    url.searchParams.set('page', page);
    Object.entries(extraParams || {}).forEach(([key, value]) => {
      if (value === undefined || value === null || value === '') url.searchParams.delete(key);
      else url.searchParams.set(key, String(value));
    });
    if (replace) history.replaceState({ page }, '', url);
    else history.pushState({ page }, '', url);
    renderPage(page);
  }

  function goLogs({ childId = '', status = 'all', period = 'all' } = {}) {
    const targetChild = childId || state.selectedChildId || 'ALL';
    go('log-activity', false, { childId: targetChild, status, period });
  }

  function goStreak({ childId = '', month = '', from = '' } = {}) {
    const targetChild = childId || activeChild()?.id || state.selectedChildId || '';
    const backTarget = from || currentPage();
    go('streak-calendar', false, {
      childId: targetChild,
      month: month || new Date().toISOString().slice(0, 7),
      from: backTarget,
    });
  }

  function currentPage() {
    return new URL(window.location.href).searchParams.get('page') || window.SNAILLY_INITIAL_PAGE || 'home';
  }

  function renderPage(page) {
    if (page !== 'child-dashboard' && state.childStatusTimer) {
      clearInterval(state.childStatusTimer);
      state.childStatusTimer = null;
    }
    pages.forEach((el) => el.classList.toggle('active', el.dataset.page === page));
    document.querySelectorAll('[data-link]').forEach((link) => {
      link.classList.toggle('active', link.dataset.link === page);
    });
    if (['dashboard','children','log-activity','rules','access-requests','schedule','report','setting','about'].includes(page) && !state.user) {
      toast('Please login as parent first.', 'error');
      go('login', true);
      return;
    }
    if (page === 'login-child') renderLoginChild();
    if (page === 'child-dashboard') loadChildDashboard();
    if (page === 'child-logs') loadChildLogs();
    if (page === 'dashboard') loadDashboard();
    if (page === 'children') loadChildren();
    if (page === 'log-activity') loadLogs();
    if (page === 'rules') loadRules();
    if (page === 'access-requests') loadRequests();
    if (page === 'schedule') loadSchedule();
    if (page === 'report') loadReport();
    if (page === 'streak-calendar') loadStreakCalendar();
    if (page === 'setting') loadSetting();
    if (page === 'blocked') renderBlocked();
  }

  document.body.addEventListener('click', (event) => {
    const link = event.target.closest('[data-link]');
    if (link) {
      event.preventDefault();
      go(link.dataset.link);
    }
    const logFilterButton = event.target.closest('[data-open-logs]');
    if (logFilterButton) {
      event.preventDefault();
      goLogs({
        childId: logFilterButton.dataset.childId || '',
        status: logFilterButton.dataset.status || 'all',
        period: logFilterButton.dataset.period || 'all',
      });
    }
    const childLogButton = event.target.closest('[data-open-child-logs]');
    if (childLogButton) {
      event.preventDefault();
      go('child-logs', false, {
        childId: childLogButton.dataset.childId || activeChild()?.id || '',
        status: childLogButton.dataset.status || 'all',
      });
    }
    const streakButton = event.target.closest('[data-open-streak]');
    if (streakButton) {
      event.preventDefault();
      goStreak({
        childId: streakButton.dataset.childId || '',
        month: streakButton.dataset.month || '',
        from: streakButton.dataset.backTo || currentPage(),
      });
    }
    if (event.target.closest('#logoutButton')) logout();
    if (event.target.closest('#childLogoutButton')) childLogout();
  });
  window.addEventListener('popstate', () => renderPage(currentPage()));

  function requireUser() {
    if (!state.user) {
      toast('Please login first.', 'error');
      go('login');
      return false;
    }
    return true;
  }

  async function logout() {
    try { await api('/auth/logout', { method: 'POST', auth: false }); } catch (_) {}
    localStorage.removeItem('snailly_user');
    localStorage.removeItem('snailly_parent_token');
    localStorage.removeItem('snailly_children');
    localStorage.removeItem('snailly_selected_child_id');
    localStorage.removeItem('snailly_current_user');
    localStorage.removeItem('snailly_kid_session');
    state.user = null;
    state.children = [];
    state.selectedChildId = '';
    state.currentUser = null;
    state.kidSession = null;
    toast('Logged out.', 'success');
    go('home');
  }

  async function childLogout() {
    try { await api('/auth/logout', { method: 'POST', auth: false }); } catch (_) {}
    setKidSession(null);
    setSelectedChild('');
    toast('Kids mode closed.', 'success');
    go('home');
  }

  document.getElementById('loginForm')?.addEventListener('submit', async (event) => {
    event.preventDefault();
    const form = new FormData(event.currentTarget);
    const submit = event.currentTarget.querySelector('button[type="submit"]');
    submit.disabled = true;
    try {
      toast('Logging you in');
      const login = await api('/auth/login', {
        method: 'POST',
        auth: false,
        data: {
          email: form.get('email'),
          password: form.get('password'),
          registrationToken: localStorage.getItem('snailly_fcm_token') || '',
        },
      });
      const user = normalizeResponse(login);
      setKidSession(null);
      setUser(user);
      const childrenResponse = await api('/child', { auth: true });
      setChildren(normalizeResponse(childrenResponse));
      toast(login.message || 'Login success.', 'success');
      go('dashboard');
    } catch (error) {
      toast(messageFromError(error), 'error');
    } finally {
      submit.disabled = false;
    }
  });

  document.getElementById('registerForm')?.addEventListener('submit', async (event) => {
    event.preventDefault();

    // Simpan referensi form di awal. Setelah proses await selesai,
    // event.currentTarget bisa berubah menjadi null di browser.
    const formEl = event.currentTarget;
    const form = new FormData(formEl);

    if (form.get('password') !== form.get('confirmPassword')) {
      toast('Passwords do not match.', 'error');
      return;
    }

    const submit = formEl.querySelector('button[type="submit"]');
    if (submit) submit.disabled = true;

    try {
      const result = await api('/auth/register', {
        method: 'POST',
        auth: false,
        data: {
          name: form.get('name'),
          email: form.get('email'),
          password: form.get('password'),
          confirmPassword: form.get('confirmPassword'),
        },
      });

      const user = normalizeResponse(result);
      setKidSession(null);
      setUser(user);
      setChildren([]);
      setSelectedChild('');
      toast(result.message || 'Registration success. Kamu otomatis masuk ke dashboard.', 'success');

      formEl.reset();
      go('dashboard');
    } catch (error) {
      toast(messageFromError(error), 'error');
    } finally {
      if (submit) submit.disabled = false;
    }
  });

  function renderLoginChild() {
    const root = document.getElementById('childLoginContent');
    const children = state.children || [];
    const quickProfiles = state.user && children.length > 0
      ? `<div class="quick-child-section">
          <p class="eyebrow">Quick enter from parent session</p>
          <div class="children-grid compact">${children.map((child) => `
            <button class="role-card child-mode-button" data-child-id="${escapeHTML(child.id)}">
              <span class="role-icon">🧒</span>
              <span>${escapeHTML(child.name)}</span>
              <small>@${escapeHTML(child.username || '-')}</small>
            </button>`).join('')}
          </div>
        </div>`
      : '';

    root.innerHTML = `
      <div class="page-heading center-text">
        <h1>Kids Login</h1>
        <p>Masuk pakai username dan password anak yang dibuat parent.</p>
      </div>
      <form id="childAccountLoginForm" class="child-login-form">
        <label>Username
          <input name="username" type="text" placeholder="contoh: farrel" required autocomplete="username">
        </label>
        <label>Password
          <input name="password" type="password" placeholder="password anak" required autocomplete="current-password">
        </label>
        <button class="btn primary" type="submit">Login as Kid</button>
      </form>
      <div class="proxy-note">Kalau belum punya akun anak, parent bisa membuatnya di menu Children dengan username dan password khusus.</div>
      ${quickProfiles}
    `;

    root.querySelector('#childAccountLoginForm')?.addEventListener('submit', handleChildAccountLogin);
    root.querySelectorAll('.child-mode-button').forEach((button) => {
      button.addEventListener('click', () => activateKidMode(button.dataset.childId));
    });
  }

  async function handleChildAccountLogin(event) {
    event.preventDefault();
    const form = new FormData(event.currentTarget);
    const submit = event.currentTarget.querySelector('button[type="submit"]');
    submit.disabled = true;
    try {
      const result = await api('/auth/child-login', {
        method: 'POST',
        auth: false,
        data: {
          username: form.get('username'),
          password: form.get('password'),
        },
      });
      const child = normalizeResponse(result);
      setUser(null);
      setChildren([]);
      setKidSession(child);
      setSelectedChild(child.id);
      toast(result.message || `Welcome, ${child.name}!`, 'success');
      go('child-dashboard');
    } catch (error) {
      toast(messageFromError(error), 'error');
    } finally {
      submit.disabled = false;
    }
  }

  async function activateKidMode(childId) {
    if (!requireUser()) return;
    try {
      toast('Preparing blocked website list...');
      const current = await api('/auth/me', { auth: true });
      const currentUser = normalizeResponse(current);
      const blocked = await api(`/classified-url/dangerous-website/${currentUser.id}`, { auth: true });
      const websites = normalizeResponse(blocked);
      await fetch('/api/snailly/blocklist?action=write', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'same-origin',
        body: JSON.stringify({ websites: websites || [] }),
      });
      const child = (state.children || []).find((item) => item.id === childId) || { id: childId, name: 'Kids' };
      setSelectedChild(childId);
      setKidSession(child);
      toast(`Welcome, ${child.name}!`, 'success');
      go('child-dashboard');
    } catch (error) {
      toast(messageFromError(error), 'error');
    }
  }


  async function loadChildDashboard() {
    const root = document.getElementById('childDashboardContent');
    const greeting = document.getElementById('childDashboardGreeting');

    if (!root) return;

    if (!state.user && !state.kidSession?.id) {
      root.innerHTML = `
        <div class="kid-panel kid-empty">
          <h2>Login anak dibutuhkan dulu</h2>
          <p>Masuk pakai username dan password anak agar dashboard bisa membaca aktivitasmu sendiri.</p>
          <a class="btn child-primary" href="?page=login-child" data-link="login-child">Login Kids</a>
        </div>
      `;

      if (greeting) greeting.textContent = 'Hi, Explorer! 👋';
      return;
    }

    if (state.user && !state.children?.length) {
      try {
        await refreshChildren();
      } catch (_) {}
    }

    const child = activeChild();

    if (!child?.id) {
      root.innerHTML = `
        <div class="kid-panel kid-empty">
          <h2>Pilih profil anak dulu</h2>
          <p>Masuk dari halaman Kids supaya dashboard ini tahu profil anak yang sedang aktif.</p>
          <a class="btn child-primary" href="?page=login-child" data-link="login-child">Choose Child</a>
        </div>
      `;

      if (greeting) greeting.textContent = 'Hi, Explorer! 👋';
      return;
    }

    setSelectedChild(child.id);
    setKidSession(child);

    if (greeting) greeting.textContent = `Hi, ${child.name}! 👋`;

    root.innerHTML = `<div class="kid-loading">Preparing your safe dashboard...</div>`;

    try {
      const now = new Date();
      const monthDate = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}`;

      const [summaryResponse, logsResponse, monthResponse, trackerResponse] = await Promise.all([
        api(`/log/summary/${child.id}`, { auth: true }),
        api(`/log/${child.id}`, { auth: true, params: { page: 1, limit: 6, period: 'all' } }),
        api(`/log/statistic-month/${child.id}`, { auth: true, params: { date: monthDate } }),
        api(`/tracker-status/${child.id}`, { auth: true }).catch(() => ({ data: { enabled: false, blockDangerous: false } })),
      ]);

      const summary = normalizeResponse(summaryResponse) || {};
      const logs = normalizeResponse(logsResponse) || { items: [] };
      const monthStats = normalizeResponse(monthResponse) || [];
      const tracker = normalizeResponse(trackerResponse) || { enabled: false, blockDangerous: false };

      updateChildSafeMode(tracker);

      if (state.childStatusTimer) clearInterval(state.childStatusTimer);

      state.childStatusTimer = setInterval(async () => {
        if (currentPage() !== 'child-dashboard') return;

        try {
          const statusResponse = await api(`/tracker-status/${child.id}`, { auth: true });
          updateChildSafeMode(normalizeResponse(statusResponse) || { enabled: false });
        } catch (_) {
          updateChildSafeMode({ enabled: false, blockDangerous: false });
        }
      }, 30000);

      root.innerHTML = childDashboardHTML(child, summary, logs.items || [], monthStats, tracker);

      root.querySelectorAll('[data-open-url]').forEach((cell) => {
        cell.addEventListener('click', () => {
          const url = cell.dataset.openUrl;
          if (url) window.open(url, '_blank', 'noopener');
        });
      });
    } catch (error) {
      root.innerHTML = `
        <div class="kid-panel kid-empty">
          <h2>Dashboard belum bisa dimuat</h2>
          <p>${escapeHTML(messageFromError(error))}</p>
          <a class="btn child-secondary" href="?page=login-child" data-link="login-child">Back</a>
        </div>
      `;
    }
  }

  function learningStreak(monthStats) {
    const byDay = new Map((Array.isArray(monthStats) ? monthStats : []).map((item) => [Number(item.month), item]));
    const today = new Date();
    let streak = 0;
    for (let d = today.getDate(); d >= 1; d--) {
      const item = byDay.get(d);
      const good = Number(item?.Good || 0);
      const bad = Number(item?.Bad || 0);
      if (good > 0 && bad === 0) streak++;
      else if (good > 0 && streak === 0) streak = 1;
      else break;
    }
    if (streak === 0) {
      streak = Math.min(7, (Array.isArray(monthStats) ? monthStats : []).filter((item) => Number(item.Good || 0) > 0).length);
    }
    return Math.max(0, streak);
  }

  function updateChildSafeMode(tracker) {
    const card = document.querySelector('.child-mascot-card');
    if (!card) return;
    const strong = card.querySelector('strong');
    const small = card.querySelector('small');
    const enabled = Boolean(tracker?.enabled);
    const block = Boolean(tracker?.blockDangerous);
    if (strong) strong.textContent = enabled ? 'Safe Mode ON' : 'Safe Mode OFF';
    if (small) small.textContent = enabled
      ? (block ? 'Risky URLs will be blocked automatically.' : 'Tracking is active, blocking is optional.')
      : 'Tracker extension is currently off.';
    card.classList.toggle('safe-mode-off', !enabled);
  }

  function childDashboardHTML(child, summary, logs, monthStats, tracker = {}) {
    const safe = Number(summary.totalSafeWebsites ?? summary.totalSafeWebsite ?? 0);
    const danger = Number(summary.totalDangerousWebsites ?? summary.totalDangerousWebsite ?? 0);
    const total = safe + danger;
    const safePercent = total > 0 ? Math.round((safe / total) * 100) : 100;
    const streak = learningStreak(monthStats);
    const recent = Array.isArray(logs) ? logs.slice(0, 5) : [];
    return `
      <section class="kid-stats-grid">
        ${kidStatCard('🟢', 'Safe Visits', safe, 'good', { childId: child.id, status: 'positive' })}
        ${kidStatCard('🛑', 'Blocked/Risky', danger, 'bad', { childId: child.id, status: 'negative' })}
        ${kidStatCard('🔥', 'Learning Streak', `${streak} days`, 'warm', { childId: child.id, streak: true })}
        ${kidStatCard('⭐', 'Safety Score', `${safePercent}%`, 'score', { childId: child.id, status: 'all' })}
      </section>

      <section class="kid-main-grid">
        <div class="kid-panel kid-score-panel">
          <div class="kid-score-ring" style="--score:${safePercent}%"><strong>${safePercent}%</strong></div>
          <div>
            <p class="eyebrow">Browsing Mood</p>
            <h2>${safePercent >= 80 ? 'Great job, keep exploring safely!' : safePercent >= 50 ? 'Lumayan aman, tapi tetap hati-hati ya.' : 'Perlu lebih hati-hati hari ini.'}</h2>
            <p class="muted">Dashboard ini menampilkan aktivitas dari extension tracker lokal. Website berisiko akan ditandai oleh Snailly.</p>
          </div>
        </div>

        <div class="kid-panel mission-card">
          <p class="eyebrow">Today Mission</p>
          <h2>3 Safety Rules</h2>
          <ul class="kid-checklist">
            <li>Jangan isi password di website yang tidak dikenal.</li>
            <li>Kalau muncul hadiah gratis yang aneh, tutup tab-nya.</li>
            <li>Tanya orang tua sebelum download file.</li>
          </ul>
        </div>
      </section>

      <section class="kid-panel">
        <div class="section-title-row">
          <div>
            <p class="eyebrow">Quick Start</p>
            <h2>Safe places to learn</h2>
          </div>
        </div>
        <div class="safe-link-grid">
          ${safeLink('📚', 'Wikipedia', 'Read simple knowledge', 'https://wikipedia.org')}
          ${safeLink('🧩', 'Scratch', 'Create and play games', 'https://scratch.mit.edu/projects/editor/')}
          ${safeLink('🦁', 'Nat Geo Kids', 'Learn animals & science', 'https://kids.nationalgeographic.com')}
          ${safeLink('🎥', 'YouTube Kids', 'Watch safer videos', 'https://www.youtubekids.com')}
        </div>
      </section>

      <section class="kid-panel">
        <div class="section-title-row">
          <div>
            <p class="eyebrow">Recent Activity</p>
            <h2>${escapeHTML(child.name)}'s latest browsing</h2>
          </div>
          <button class="btn child-secondary" type="button" data-open-child-logs data-status="all">My Logs</button>
        </div>
        ${childLogsHTML(recent)}
      </section>
    `;
  }

  function kidStatCard(icon, title, value, variant, action = {}) {
    const attrs = action.streak
      ? `data-open-streak data-child-id="${escapeHTML(action.childId || '')}" data-back-to="child-dashboard"`
      : `data-open-child-logs data-child-id="${escapeHTML(action.childId || '')}" data-status="${escapeHTML(action.status || 'all')}"`;
    return `<button type="button" class="kid-stat ${variant} clickable-card" ${attrs}><span>${icon}</span><p>${escapeHTML(title)}</p><strong>${escapeHTML(value)}</strong><small>Click to view</small></button>`;
  }

  function safeLink(icon, title, subtitle, url) {
    return `<a class="safe-link-card" href="${escapeHTML(url)}" target="_blank" rel="noopener"><span>${icon}</span><strong>${escapeHTML(title)}</strong><small>${escapeHTML(subtitle)}</small></a>`;
  }

  function childLogsHTML(items) {
    if (!Array.isArray(items) || items.length === 0) return '<div class="kid-empty-mini">Belum ada aktivitas. Coba buka website setelah extension aktif.</div>';
    return `<div class="kid-activity-list">${items.map((item) => {
      const [label, kind] = statusOf(item);
      const icon = kind === 'positive' ? '✅' : kind === 'negative' ? '⚠️' : '🕒';
      return `<button class="kid-activity-item" data-open-url="${escapeHTML(item.url || '')}">
        <span class="activity-icon ${kind}">${icon}</span>
        <span class="activity-main"><strong>${escapeHTML(item.web_title || item.url || 'Unknown website')}</strong><small>${escapeHTML(item.url || '')}</small></span>
        <span class="status ${kind}">${label}</span>
      </button>`;
    }).join('')}</div>`;
  }

  async function loadChildLogs(page = 1) {
    const root = document.getElementById('childLogsContent');
    const title = document.getElementById('childLogsTitle');
    const back = document.getElementById('childLogsBackButton');
    if (!root) return;
    const params = new URL(window.location.href).searchParams;
    const childId = params.get('childId') || state.kidSession?.id || activeChild()?.id || '';
    const status = params.get('status') || 'all';
    const child = (state.children || []).find((c) => c.id === childId) || (state.kidSession?.id === childId ? state.kidSession : activeChild());
    if (back) back.onclick = () => go('child-dashboard');
    if (!child?.id) {
      root.innerHTML = `<div class="kid-panel kid-empty"><h2>Login anak dibutuhkan dulu</h2><p>Masuk sebagai anak untuk melihat log milikmu.</p><a class="btn child-primary" href="?page=login-child" data-link="login-child">Login Kids</a></div>`;
      return;
    }
    if (title) {
      title.textContent = `${child.name}'s ${
        status === 'positive'
          ? 'Safe'
          : status === 'negative'
            ? 'Risky / Blocked'
            : status === 'warning'
              ? 'Warning'
              : 'Browsing'
      } Logs`;
    }    
    root.innerHTML = `<div class="kid-loading">Loading your logs...</div>`;
    try {
      const response = await api(`/log/${child.id}`, { auth: true, params: { page, limit: 12, period: 'all', status } });
      const logs = normalizeResponse(response) || { items: [] };
      root.innerHTML = childLogsPageHTML(logs.items || [], logs, status);
      root.querySelectorAll('[data-page-number]').forEach((button) => button.addEventListener('click', () => loadChildLogs(Number(button.dataset.pageNumber))));
      root.querySelectorAll('[data-open-url]').forEach((cell) => cell.addEventListener('click', () => {
        const url = cell.dataset.openUrl;
        if (url) window.open(url, '_blank', 'noopener');
      }));
    } catch (error) {
      root.innerHTML = `<div class="kid-panel kid-empty"><h2>Log belum bisa dimuat</h2><p>${escapeHTML(messageFromError(error))}</p></div>`;
    }
  }

  function childLogsPageHTML(items, paging, status) {
    const label = status === 'positive' ? 'Safe Visits' : status === 'negative' ? 'Risky / Blocked Visits' : 'All Visits';
    return `<section class="kid-panel child-log-panel">
      <div class="section-title-row">
        <div><p class="eyebrow">${escapeHTML(label)}</p><h2>Your browsing history</h2></div>
      </div>
      ${childLogsHTML(items)}
      ${pagingHTML(paging)}
    </section>`;
  }

  async function renderGlobalChildFilter() {
    const select = document.getElementById('globalChildFilter');
    if (!select) return;
    const children = state.children || [];
    select.innerHTML = `<option value="">All Children</option>${children.map((child) => `<option value="${escapeHTML(child.id)}">${escapeHTML(child.name)}</option>`).join('')}`;
    select.value = state.selectedChildId || '';
  }

  document.getElementById('globalChildFilter')?.addEventListener('change', (event) => {
    setSelectedChild(event.target.value);
    loadDashboard();
  });

  async function refreshChildren() {
    const response = await api('/child', { auth: true });
    const children = normalizeResponse(response) || [];
    setChildren(children);
    return children;
  }

  async function loadDashboard() {
    if (!requireUser()) return;
    renderGlobalChildFilter();
    const root = document.getElementById('dashboardContent');
    root.innerHTML = `<div class="loading">Loading dashboard...</div>`;
    try {
      if (!state.children?.length) await refreshChildren();
      const childId = state.selectedChildId || 'ALL';
      const now = new Date();
      const year = now.getFullYear();
      const monthDate = `${year}-${String(now.getMonth() + 1).padStart(2, '0')}`;
      const overviewResponse = await api(`/dashboard/overview/${childId}`, {
        auth: true,
        params: { year, date: monthDate, limit: 5 },
      });
      const overview = normalizeResponse(overviewResponse) || {};
      const summary = overview.summary || {};
      const logs = overview.logs || { items: [] };
      const yearStats = overview.yearStats || [];
      const monthStats = overview.monthStats || [];
      const report = overview.report || {};
      root.innerHTML = dashboardHTML(summary, logs, yearStats, monthStats, report);
      bindGrantButtons(root, () => loadDashboard());
    } catch (error) {
      root.innerHTML = `<div class="panel empty-state">${escapeHTML(messageFromError(error))}</div>`;
    }
  }

  function dashboardHTML(summary, logs, yearStats, monthStats, report = {}) {
    const safe = Number(summary.totalSafeWebsites ?? summary.totalSafeWebsite ?? 0);
    const danger = Number(summary.totalDangerousWebsites ?? summary.totalDangerousWebsite ?? 0);
    const total = safe + danger;
    const goodPercent = total > 0 ? Math.round((safe / total) * 100) : 0;
    return `
      <section class="card-grid">
        ${statCard('🌐', 'Total Accessed Content', total, '', 'all')}
        ${statCard('😊', 'Total Positive Content', safe, 'positive', 'positive')}
        ${statCard('☹️', 'Total Negative Content', danger, 'negative', 'negative')}
      </section>
      <section class="viz-grid">
        <div class="panel chart-panel">
          <h2>Statistics Accessed Content</h2>
          ${barChartHTML(yearStats)}
        </div>
        <div class="panel pie-card">
          <h2>Monthly Percentage</h2>
          <div class="donut" style="--p:${goodPercent}%"><strong>${goodPercent}%</strong></div>
          <p>Your child accessed ${goodPercent}% positive content</p>
        </div>
      </section>
      <section class="feature-grid two-cols">
        <div class="panel"><h2>Top Visited Website</h2>${hostListHTML(report.topHosts || {})}</div>
        <div class="panel"><h2>Top Risky / Blocked Website</h2>${hostListHTML(report.topRiskyHosts || {})}</div>
      </section>
      <section class="panel">
        <h2>New Activity</h2>
        ${logsTableHTML(logs.items || [])}
      </section>
    `;
  }

  function statCard(icon, title, value, variant, status = 'all', childId = '') {
    return `<button class="stat-card ${variant} clickable-card" type="button" data-open-logs data-status="${escapeHTML(status)}" data-child-id="${escapeHTML(childId || state.selectedChildId || 'ALL')}" data-period="all" title="Lihat log ${escapeHTML(title)}">
      <span class="icon">${icon}</span>
      <span>${escapeHTML(title)}</span>
      <strong>${escapeHTML(value)}</strong>
      <small>Click to view logs</small>
    </button>`;
  }

  function hostListHTML(hosts) {
    const entries = Object.entries(hosts || {}).slice(0, 8);
    if (!entries.length) return '<div class="empty-state">No host data yet.</div>';
    return `<div class="category-list">${entries.map(([host, count]) => `<div><span>${escapeHTML(host)}</span><strong>${escapeHTML(count)}</strong></div>`).join('')}</div>`;
  }

  function barChartHTML(items) {
    if (!Array.isArray(items) || items.length === 0) return '<div class="empty-state">No statistic data available.</div>';
    const max = Math.max(1, ...items.flatMap((i) => [Number(i.Good || 0), Number(i.Bad || 0)]));
    return `<div class="bar-chart">${items.slice(0, 12).map((item) => {
      const goodH = Math.max(3, Math.round((Number(item.Good || 0) / max) * 100));
      const badH = Math.max(3, Math.round((Number(item.Bad || 0) / max) * 100));
      const label = String(item.month || '').slice(0, 3) || '-';
      const good = Number(item.Good || 0);
      const bad = Number(item.Bad || 0);
      return `<div class="bar-pair" tabindex="0" aria-label="${escapeHTML(label)}: ${good} positive, ${bad} negative">
        <div class="bar good" style="height:${goodH}%"><span class="bar-tooltip">Positive: ${good}</span></div>
        <div class="bar bad" style="height:${badH}%"><span class="bar-tooltip">Negative: ${bad}</span></div>
        <span class="bar-label">${escapeHTML(label)}</span>
      </div>`;
    }).join('')}</div>`;
  }

  function statusOf(item) {
    const labels = Array.isArray(item.classified_url) ? item.classified_url : [];
    const label = labels[0]?.FINAL_label;
    const action = labels[0]?.action;

    if (label === 'peringatan' || action === 'warn') {
      return ['Warning', 'warning'];
    }

    if (labels.length > 0) {
      if (label !== 'aman' && label !== 'bahaya' && item.grant_access === null) {
        return ['Not Labelling', 'pending'];
      }

      if (label === 'aman' && item.grant_access !== false) {
        return ['Positive', 'positive'];
      }

      return ['Negative', 'negative'];
    }

    if (item.grant_access === true) return ['Positive', 'positive'];
    if (item.grant_access === false) return ['Negative', 'negative'];

    return ['Not Labelling', 'pending'];
  }

  function categoryOfLog(item) {
    const labels = Array.isArray(item.classified_url) ? item.classified_url : [];
    return item.risk_category || labels[0]?.category || (statusOf(item)[1] === 'positive' ? 'Safe' : 'Risky');
  }

  function reasonOfLog(item) {
    const labels = Array.isArray(item.classified_url) ? item.classified_url : [];
    return item.risk_reason || labels[0]?.reason || '';
  }

  function logsTableHTML(items) {
    if (!Array.isArray(items) || items.length === 0) return '<div class="empty-state">No Log Activity Available</div>';
    return `<div class="table-wrap"><table><thead><tr><th>No</th><th>URL</th><th>Date</th><th>Children Name</th><th>Category</th><th>Status</th><th>Action</th></tr></thead><tbody>${items.map((item, index) => {
      const [label, kind] = statusOf(item);
      const nextGrant = !(item.grant_access === true);
      const category = categoryOfLog(item);
      const reason = reasonOfLog(item);
      return `<tr>
        <td>${index + 1}</td>
        <td class="url-cell" title="${escapeHTML(item.url || '')}" data-open-url="${escapeHTML(item.url || '')}">${escapeHTML(item.url || 'unknown')}</td>
        <td>${escapeHTML(formatDateIndonesia(item.createdAt))}</td>
        <td>${escapeHTML(item.child?.name || '-')}</td>
        <td><span class="category-pill" title="${escapeHTML(reason)}">${escapeHTML(category)}</span></td>
        <td><span class="status ${kind}">${label}</span></td>
        <td><button class="btn ghost grant-button" data-log-id="${escapeHTML(item.log_id)}" data-url="${escapeHTML(item.url || '')}" data-grant="${nextGrant}">${item.grant_access === true ? '🔓' : '🔒'}</button></td>
      </tr>`;
    }).join('')}</tbody></table></div>`;
  }

  function bindGrantButtons(root, reload) {
    root.querySelectorAll('[data-open-url]').forEach((cell) => {
      cell.addEventListener('click', () => {
        const url = cell.dataset.openUrl;
        if (url) window.open(url, '_blank', 'noopener');
      });
    });
    root.querySelectorAll('.grant-button').forEach((button) => {
      button.addEventListener('click', () => confirmGrant(button.dataset.logId, button.dataset.grant === 'true', button.dataset.url, reload));
    });
  }

  function showModal(title, bodyHTML, actionsHTML) {
    modalTitle.textContent = title;
    modalBody.innerHTML = bodyHTML;
    modalActions.innerHTML = actionsHTML;
    if (typeof modal.showModal === 'function') modal.showModal();
    else modal.setAttribute('open', 'open');
  }
  function closeModal() {
    if (typeof modal.close === 'function') modal.close();
    else modal.removeAttribute('open');
  }

  function confirmGrant(logId, grantAccess, url, reload) {
    const title = grantAccess ? 'Are you sure to unlock this website?' : 'Are you sure to lock this website?';
    showModal(title, `<p class="url-box">${escapeHTML(url)}</p>`, `<button class="btn secondary" data-modal-cancel>No</button><button class="btn primary" data-modal-confirm>Yes</button>`);
    modalActions.querySelector('[data-modal-cancel]').addEventListener('click', closeModal);
    modalActions.querySelector('[data-modal-confirm]').addEventListener('click', async () => {
      try {
        await api(`/log/grant-access/${logId}`, { method: 'PUT', auth: true, data: { grantAccess: String(grantAccess) } });
        toast('Access updated.', 'success');
        closeModal();
        reload?.();
      } catch (error) {
        toast(messageFromError(error), 'error');
      }
    });
  }

  document.getElementById('openAddChild')?.addEventListener('click', () => openChildModal('add'));

  async function loadChildren() {
    if (!requireUser()) return;
    const root = document.getElementById('childrenContent');
    root.innerHTML = `<div class="loading">Loading children...</div>`;
    try {
      const overviewResponse = await api('/children/overview', {
        auth: true,
        params: { date: new Date().toISOString().slice(0, 7) },
      });
      const overview = normalizeResponse(overviewResponse) || {};
      const children = overview.children || [];
      setChildren(children);
      const summaryMap = overview.summaryMap || {};
      root.innerHTML = childrenHTML(children, summaryMap);
      root.querySelectorAll('[data-edit-child]').forEach((button) => {
        button.addEventListener('click', () => openChildModal('edit', button.dataset.editChild, button.dataset.name, button.dataset.username || ''));
      });
      root.querySelectorAll('[data-delete-child]').forEach((button) => {
        button.addEventListener('click', () => openChildModal('delete', button.dataset.deleteChild, button.dataset.name));
      });
    } catch (error) {
      root.innerHTML = `<div class="panel empty-state">${escapeHTML(messageFromError(error))}</div>`;
    }
  }

  function childrenHTML(children, summaryMap = {}) {
    if (!Array.isArray(children) || children.length === 0) return '<div class="panel empty-state">There is no children data.</div>';
    return `<div class="children-grid rich-children-grid">${children.map((child) => {
      const data = summaryMap[child.id] || {};
      const summary = data.summary || {};
      const safe = Number(summary.totalSafeWebsites ?? summary.totalSafeWebsite ?? 0);
      const danger = Number(summary.totalDangerousWebsites ?? summary.totalDangerousWebsite ?? 0);
      const total = safe + danger;
      const score = total > 0 ? Math.round((safe / total) * 100) : 100;
      const streak = learningStreak(data.monthStats || []);
      return `
      <div class="panel child-card child-card-rich">
        <div class="child-card-head">
          <div class="child-avatar">🧒</div>
          <div>
            <h2>${escapeHTML(child.name)}</h2>
            <p class="muted">Username: <strong>@${escapeHTML(child.username || '-')}</strong></p>
          </div>
        </div>
        <div class="child-mini-stats">
          <button type="button" data-open-logs data-child-id="${escapeHTML(child.id)}" data-status="positive" data-period="all"><strong>${safe}</strong><span>Safe</span></button>
          <button type="button" data-open-logs data-child-id="${escapeHTML(child.id)}" data-status="negative" data-period="all"><strong>${danger}</strong><span>Risky/Blocked</span></button>
          <button type="button" data-open-streak data-child-id="${escapeHTML(child.id)}"><strong>${streak}</strong><span>Streak days</span></button>
          <button type="button" data-open-logs data-child-id="${escapeHTML(child.id)}" data-status="all" data-period="all"><strong>${score}%</strong><span>Score</span></button>
        </div>
        <p class="muted small-child-id">ID: ${escapeHTML(child.id)}</p>
        <div class="card-actions">
          <button class="btn secondary" data-edit-child="${escapeHTML(child.id)}" data-name="${escapeHTML(child.name)}" data-username="${escapeHTML(child.username || '')}">Edit</button>
          <button class="btn danger" data-delete-child="${escapeHTML(child.id)}" data-name="${escapeHTML(child.name)}">Delete</button>
        </div>
      </div>`;
    }).join('')}</div>`;
  }

  function openChildModal(mode, id = '', name = '', username = '') {
    const isDelete = mode === 'delete';
    const isEdit = mode === 'edit';
    const title = mode === 'add' ? 'Add Child Account' : mode === 'edit' ? 'Edit Child Account' : 'Delete Child';
    const body = isDelete
      ? `<p>Are you sure to delete <strong>${escapeHTML(name)}</strong>?</p>`
      : `<div class="modal-form-stack">
          <label>Name
            <input id="childNameInput" value="${escapeHTML(name)}" placeholder="Type child name" required>
          </label>
          <label>Username
            <input id="childUsernameInput" value="${escapeHTML(username)}" placeholder="contoh: farrel" required>
            <small class="muted">Dipakai anak untuk login. Username huruf kecil/angka/titik/underscore/strip. Password minimal 8 karakter dengan huruf dan angka.</small>
          </label>
          <label>Password ${isEdit ? '<span class="muted">(kosongkan kalau tidak diganti)</span>' : ''}
            <input id="childPasswordInput" type="password" placeholder="Minimal 8 karakter, huruf + angka" ${isEdit ? '' : 'required'}>
          </label>
          <label>Confirm Password
            <input id="childConfirmPasswordInput" type="password" placeholder="Ulangi password" ${isEdit ? '' : 'required'}>
          </label>
        </div>`;
    const actions = `<button class="btn secondary" data-modal-cancel>Cancel</button><button class="btn ${isDelete ? 'danger' : 'primary'}" data-modal-confirm>${isDelete ? 'Delete' : 'Save'}</button>`;
    showModal(title, body, actions);
    modalActions.querySelector('[data-modal-cancel]').addEventListener('click', closeModal);
    modalActions.querySelector('[data-modal-confirm]').addEventListener('click', async () => {
      try {
        if (mode === 'add' || mode === 'edit') {
          const value = document.getElementById('childNameInput').value.trim();
          const userValue = document.getElementById('childUsernameInput').value.trim();
          const passValue = document.getElementById('childPasswordInput').value;
          const confirmValue = document.getElementById('childConfirmPasswordInput').value;
          if (!value) return toast('Name is required.', 'error');
          if (!userValue) return toast('Username anak wajib diisi.', 'error');
          if (passValue !== confirmValue) return toast('Password anak tidak sama.', 'error');
          const payload = { name: value, username: userValue };
          if (mode === 'add' || passValue !== '') {
            payload.password = passValue;
            payload.confirmPassword = confirmValue;
          }
          if (mode === 'add') await api('/child/', { method: 'POST', auth: true, data: payload });
          else await api(`/child/${id}`, { method: 'PUT', auth: true, data: payload });
        } else {
          await api(`/child/${id}`, { method: 'DELETE', auth: true, data: {} });
          if (state.selectedChildId === id) setSelectedChild('');
        }
        toast('Children data updated.', 'success');
        closeModal();
        loadChildren();
      } catch (error) {
        toast(messageFromError(error), 'error');
      }
    });
  }

  document.getElementById('logPeriod')?.addEventListener('change', syncLogInputs);
  document.getElementById('logPeriod')?.addEventListener('change', () => { syncLogUrlFromControls(); loadLogs(); });
  document.getElementById('logDate')?.addEventListener('change', () => loadLogs());
  document.getElementById('logMonth')?.addEventListener('change', () => loadLogs());
  document.getElementById('logChildFilter')?.addEventListener('change', () => { syncLogUrlFromControls(); loadLogs(); });
  document.getElementById('logStatusFilter')?.addEventListener('change', () => { syncLogUrlFromControls(); loadLogs(); });
  document.getElementById('logSearch')?.addEventListener('input', debounce(() => { syncLogUrlFromControls(); loadLogs(); }, 320));
  document.getElementById('exportLogsButton')?.addEventListener('click', exportLogsCSV);
  document.getElementById('clearLogsButton')?.addEventListener('click', clearLogsWithConfirm);
  document.getElementById('requestStatusFilter')?.addEventListener('change', () => loadRequests());
  document.getElementById('reportChildFilter')?.addEventListener('change', () => loadReport());
  document.getElementById('reportPeriod')?.addEventListener('change', () => loadReport());
  document.getElementById('reportDate')?.addEventListener('change', () => loadReport());
  document.getElementById('printReportButton')?.addEventListener('click', printReportOnly);

  function syncLogInputs() {
    const period = document.getElementById('logPeriod')?.value || 'daily';
    const date = document.getElementById('logDate');
    const month = document.getElementById('logMonth');
    if (date) date.style.display = period === 'daily' ? '' : 'none';
    if (month) month.style.display = period === 'monthly' ? '' : 'none';
  }

  function syncLogUrlFromControls() {
    const url = new URL(window.location.href);
    const child = document.getElementById('logChildFilter')?.value || 'ALL';
    const status = document.getElementById('logStatusFilter')?.value || 'all';
    const period = document.getElementById('logPeriod')?.value || 'daily';
    const q = document.getElementById('logSearch')?.value?.trim() || '';
    url.searchParams.set('page', 'log-activity');
    url.searchParams.set('childId', child);
    url.searchParams.set('status', status);
    url.searchParams.set('period', period);
    if (q) url.searchParams.set('q', q);
    else url.searchParams.delete('q');
    history.replaceState({ page: 'log-activity' }, '', url);
  }

  function renderLogFiltersFromUrl() {
    const params = new URL(window.location.href).searchParams;
    const childSelect = document.getElementById('logChildFilter');
    const statusSelect = document.getElementById('logStatusFilter');
    const periodSelect = document.getElementById('logPeriod');
    const searchInput = document.getElementById('logSearch');
    if (childSelect) {
      const children = state.children || [];
      childSelect.innerHTML = `<option value="ALL">All Children</option>${children.map((child) => `<option value="${escapeHTML(child.id)}">${escapeHTML(child.name)}</option>`).join('')}`;
      const value = params.get('childId') || state.selectedChildId || 'ALL';
      childSelect.value = [...childSelect.options].some((o) => o.value === value) ? value : 'ALL';
    }
    if (statusSelect) {
      const status = params.get('status') || 'all';
      statusSelect.value = ['all', 'positive', 'negative', 'warning', 'pending'].includes(status) ? status : 'all';
    }
    if (periodSelect) {
      const period = params.get('period') || periodSelect.value || 'daily';
      periodSelect.value = ['daily', 'monthly', 'all'].includes(period) ? period : 'daily';
    }
    if (searchInput) searchInput.value = params.get('q') || searchInput.value || '';
  }


  function currentLogParams(page = 1, limit = 10) {
    const period = document.getElementById('logPeriod')?.value || 'daily';
    const childSelectValue = document.getElementById('logChildFilter')?.value || 'ALL';
    const childId = childSelectValue === '' ? 'ALL' : childSelectValue;
    const status = document.getElementById('logStatusFilter')?.value || 'all';
    const q = document.getElementById('logSearch')?.value?.trim() || '';
    const params = { page, limit, period, status, q };
    const dayValue = document.getElementById('logDate')?.value;
    const monthValue = document.getElementById('logMonth')?.value;
    if (period === 'daily') {
      const source = dayValue ? new Date(dayValue) : new Date();
      params.month = source.getMonth() + 1;
      params.year = source.getFullYear();
      params.date = source.getDate();
    }
    if (period === 'monthly') {
      const parts = monthValue ? monthValue.split('-') : [new Date().getFullYear(), new Date().getMonth() + 1];
      params.year = Number(parts[0]);
      params.month = Number(parts[1]);
    }
    return { childId, params };
  }

  function csvEscape(value) {
    const text = String(value ?? '');
    return /[",\n\r]/.test(text) ? `"${text.replace(/"/g, '""')}"` : text;
  }

  async function exportLogsCSV() {
    if (!requireUser()) return;
    try {
      const logs = [];
      let page = 1;
      let totalPage = 1;
      let childId = 'ALL';
      do {
        const current = currentLogParams(page, 100);
        childId = current.childId;
        const response = await api(`/log/${childId}`, { auth: true, params: current.params });
        const payload = normalizeResponse(response) || {};
        logs.push(...(payload.items || []));
        totalPage = Number(payload.totalPage || 1);
        page += 1;
      } while (page <= totalPage && page <= 200);
      if (!logs.length) {
        toast('Tidak ada log untuk diexport.', 'error');
        return;
      }
      const rows = [
        ['No', 'URL', 'Date', 'Child', 'Category', 'Status', 'Reason'],
        ...logs.map((item, index) => {
          const [label] = statusOf(item);
          return [index + 1, item.url || '', formatDateIndonesia(item.createdAt), item.child?.name || '-', categoryOfLog(item), label, reasonOfLog(item)];
        })
      ];
      const csv = rows.map((row) => row.map(csvEscape).join(',')).join('\r\n');
      const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
      const a = document.createElement('a');
      a.href = URL.createObjectURL(blob);
      a.download = `snailly_logs_${new Date().toISOString().slice(0, 10)}.csv`;
      document.body.appendChild(a);
      a.click();
      URL.revokeObjectURL(a.href);
      a.remove();
      toast('CSV exported.', 'success');
    } catch (error) {
      toast(messageFromError(error), 'error');
    }
  }

  function clearLogsWithConfirm() {
    if (!requireUser()) return;
    const childLabel = document.getElementById('logChildFilter')?.selectedOptions?.[0]?.textContent || 'selected filter';
    const statusLabel = document.getElementById('logStatusFilter')?.selectedOptions?.[0]?.textContent || 'selected status';
    const periodLabel = document.getElementById('logPeriod')?.selectedOptions?.[0]?.textContent || 'selected period';
    showModal(
      'Clear Activity Logs?',
      `<p>Log yang sesuai filter ini akan dihapus permanen.</p><div class="delete-summary"><span>${escapeHTML(childLabel)}</span><span>${escapeHTML(statusLabel)}</span><span>${escapeHTML(periodLabel)}</span></div><p class="muted">Gunakan Export CSV dulu kalau ingin menyimpan backup.</p>`,
      `<button class="btn secondary" data-modal-cancel>Cancel</button><button class="btn danger" data-modal-confirm>Clear Logs</button>`
    );
    modalActions.querySelector('[data-modal-cancel]').addEventListener('click', closeModal);
    modalActions.querySelector('[data-modal-confirm]').addEventListener('click', async () => {
      try {
        const { childId, params } = currentLogParams(1, 1000);
        const result = await api(`/log/${childId}`, { method: 'DELETE', auth: true, params });
        toast(result.message || 'Logs cleared.', 'success');
        closeModal();
        loadLogs(1);
      } catch (error) {
        toast(messageFromError(error), 'error');
      }
    });
  }

  async function loadLogs(page = 1) {
    if (!requireUser()) return;
    const root = document.getElementById('logActivityContent');
    root.innerHTML = `<div class="loading">Loading logs...</div>`;
    try {
      if (!state.children?.length) await refreshChildren();
      renderLogFiltersFromUrl();
      syncLogInputs();
      const period = document.getElementById('logPeriod')?.value || 'daily';
      const childSelectValue = document.getElementById('logChildFilter')?.value || 'ALL';
      const childId = childSelectValue === '' ? 'ALL' : childSelectValue;
      const status = document.getElementById('logStatusFilter')?.value || 'all';
      const q = document.getElementById('logSearch')?.value?.trim() || '';
      if (childId !== 'ALL') setSelectedChild(childId);
      const params = { page, limit: 10, period, status, q };
      const dayValue = document.getElementById('logDate')?.value;
      const monthValue = document.getElementById('logMonth')?.value;
      if (period === 'daily') {
        const source = dayValue ? new Date(dayValue) : new Date();
        params.month = source.getMonth() + 1;
        params.year = source.getFullYear();
        params.date = source.getDate();
      }
      if (period === 'monthly') {
        const parts = monthValue ? monthValue.split('-') : [new Date().getFullYear(), new Date().getMonth() + 1];
        params.year = Number(parts[0]);
        params.month = Number(parts[1]);
      }
      const response = await api(`/log/${childId}`, { auth: true, params });
      const logs = normalizeResponse(response) || { items: [] };
      const statusLabel = status === 'positive' ? 'Positive' : status === 'negative' ? 'Negative' : status === 'pending' ? 'Not Labelled' : 'All Status';
      const childLabel = childId === 'ALL' ? 'All Children' : (state.children || []).find((c) => c.id === childId)?.name || 'Selected Child';
      root.innerHTML = `<div class="panel">
        <div class="log-filter-summary"><strong>${escapeHTML(childLabel)}</strong><span>${escapeHTML(statusLabel)}</span><span>${escapeHTML(period)}</span>${q ? `<span>Search: ${escapeHTML(q)}</span>` : ''}<span>${Number(logs.total || 0)} result(s)</span></div>
        ${logsTableHTML(logs.items || [])}${pagingHTML(logs)}
      </div>`;
      bindGrantButtons(root, () => loadLogs(logs.page || 1));
      root.querySelectorAll('[data-page-number]').forEach((button) => button.addEventListener('click', () => loadLogs(Number(button.dataset.pageNumber))));
    } catch (error) {
      root.innerHTML = `<div class="panel empty-state">${escapeHTML(messageFromError(error))}</div>`;
    }
  }

  function pagingHTML(logs) {
    const page = Number(logs.page || 1);
    const totalPage = Number(logs.totalPage || 1);
    if (totalPage <= 1) return '';
    return `<div class="modal-actions"><button class="btn secondary" ${page <= 1 ? 'disabled' : ''} data-page-number="${page - 1}">Previous</button><span class="muted">Page ${page} of ${totalPage}</span><button class="btn secondary" ${page >= totalPage ? 'disabled' : ''} data-page-number="${page + 1}">Next</button></div>`;
  }


  async function loadStreakCalendar() {
    const root = document.getElementById('streakCalendarContent');
    const title = document.getElementById('streakCalendarTitle');
    const childSelect = document.getElementById('streakChildFilter');
    const monthInput = document.getElementById('streakMonth');
    const backButton = document.getElementById('streakBackButton');
    if (!root) return;

    const params = new URL(window.location.href).searchParams;
    const month = params.get('month') || monthInput?.value || new Date().toISOString().slice(0, 7);
    if (monthInput) monthInput.value = month;

    try {
      if (state.user && !state.children?.length) await refreshChildren();
      const childFromUrl = params.get('childId') || '';
      const child = childFromUrl
        ? ((state.children || []).find((c) => c.id === childFromUrl) || (state.kidSession?.id === childFromUrl ? state.kidSession : null))
        : activeChild();

      if (!child?.id) {
        root.innerHTML = `<div class="kid-panel kid-empty"><h2>Pilih anak dulu</h2><p>Calendar streak butuh profil anak agar datanya bisa ditampilkan.</p><a class="btn child-primary" href="?page=login-child" data-link="login-child">Choose Child</a></div>`;
        if (title) title.textContent = 'Browsing Streak';
        return;
      }

      if (childSelect) {
        const options = state.user
          ? (state.children || [])
          : [child];
        childSelect.innerHTML = options.map((c) => `<option value="${escapeHTML(c.id)}">${escapeHTML(c.name)}</option>`).join('');
        childSelect.value = child.id;
        childSelect.onchange = () => goStreak({ childId: childSelect.value, month: monthInput?.value || month });
      }
      if (monthInput) monthInput.onchange = () => goStreak({ childId: child.id, month: monthInput.value });
      if (backButton) backButton.onclick = () => {
        const backTarget = params.get('from') || '';
        if (backTarget === 'child-dashboard') {
          go('child-dashboard');
          return;
        }
        if (backTarget === 'children' || backTarget === 'dashboard') {
          go(backTarget);
          return;
        }
        if (state.kidSession?.id === child.id) go('child-dashboard');
        else if (state.user) go('children');
        else go('child-dashboard');
      };

      setSelectedChild(child.id);
      if (title) title.textContent = `${child.name}'s Streak Calendar`;
      root.innerHTML = `<div class="loading">Loading streak calendar...</div>`;
      const statsResponse = await api(`/log/statistic-month/${child.id}`, { auth: true, params: { date: month } });
      const stats = normalizeResponse(statsResponse) || [];
      root.innerHTML = streakCalendarHTML(child, month, stats);
    } catch (error) {
      root.innerHTML = `<div class="kid-panel kid-empty"><h2>Calendar belum bisa dimuat</h2><p>${escapeHTML(messageFromError(error))}</p></div>`;
    }
  }

  function streakCalendarHTML(child, monthValue, stats) {
    const [year, month] = monthValue.split('-').map(Number);
    const daysInMonth = new Date(year, month, 0).getDate();
    const firstDay = new Date(year, month - 1, 1).getDay();
    const byDay = new Map((Array.isArray(stats) ? stats : []).map((item) => [Number(item.month), item]));
    const safeDays = [];
    const riskyDays = [];
    for (let d = 1; d <= daysInMonth; d++) {
      const item = byDay.get(d) || {};
      const good = Number(item.Good || 0);
      const bad = Number(item.Bad || 0);
      if (good > 0 && bad === 0) safeDays.push(d);
      if (bad > 0) riskyDays.push(d);
    }
    const streak = learningStreak(stats);
    const blanks = Array.from({ length: firstDay }, () => `<div class="calendar-day blank"></div>`).join('');
    const days = Array.from({ length: daysInMonth }, (_, i) => {
      const day = i + 1;
      const item = byDay.get(day) || {};
      const good = Number(item.Good || 0);
      const bad = Number(item.Bad || 0);
      const kind = bad > 0 ? 'risky-day' : good > 0 ? 'streak-day' : 'empty-day';
      const badge = bad > 0 ? '⚠️' : good > 0 ? '🔥' : '';
      return `<div class="calendar-day ${kind}" title="${good} positive, ${bad} negative">
        <strong>${day}</strong>
        <span>${badge}</span>
        <small>${good + bad > 0 ? `${good} safe • ${bad} risky` : 'No log'}</small>
      </div>`;
    }).join('');
    const monthLabel = new Intl.DateTimeFormat('id-ID', { month: 'long', year: 'numeric' }).format(new Date(year, month - 1, 1));
    return `
      <section class="streak-summary-grid">
        <div class="kid-stat good"><span>🔥</span><p>Current Streak</p><strong>${streak} days</strong></div>
        <div class="kid-stat good"><span>✅</span><p>Safe Days</p><strong>${safeDays.length}</strong></div>
        <div class="kid-stat bad"><span>⚠️</span><p>Risky Days</p><strong>${riskyDays.length}</strong></div>
        <div class="kid-stat score"><span>📅</span><p>Month</p><strong>${escapeHTML(monthLabel)}</strong></div>
      </section>
      <section class="kid-panel calendar-panel">
        <div class="section-title-row">
          <div>
            <p class="eyebrow">${escapeHTML(child.name)}</p>
            <h2>Safe browsing calendar</h2>
          </div>
          <div class="calendar-legend"><span class="legend-safe">🔥 Safe streak</span><span class="legend-risky">⚠️ Risky/blocked</span><span class="legend-empty">No log</span></div>
        </div>
        <div class="calendar-weekdays"><span>Sun</span><span>Mon</span><span>Tue</span><span>Wed</span><span>Thu</span><span>Fri</span><span>Sat</span></div>
        <div class="calendar-grid">${blanks}${days}</div>
      </section>
    `;
  }


  async function loadRules() {
    if (!requireUser()) return;
    const root = document.getElementById('rulesContent');
    root.innerHTML = `<div class="loading">Loading rules...</div>`;
    try {
      if (!state.children?.length) await refreshChildren();
      const rulesResponse = await api('/rules', { auth: true });
      const rules = normalizeResponse(rulesResponse) || [];
      root.innerHTML = rulesHTML(rules);
      const form = root.querySelector('#ruleForm');
      form?.addEventListener('submit', async (event) => {
        event.preventDefault();
        const fd = new FormData(form);
        try {
          await api('/rules', { method: 'POST', auth: true, data: {
            type: fd.get('type'),
            matchType: fd.get('matchType'),
            childId: fd.get('childId'),
            pattern: fd.get('pattern'),
            category: fd.get('category'),
          }});
          toast('Rule saved.', 'success');
          loadRules();
        } catch (error) { toast(messageFromError(error), 'error'); }
      });
      root.querySelectorAll('[data-delete-rule]').forEach((button) => {
        button.addEventListener('click', async () => {
          try { await api(`/rules/${button.dataset.deleteRule}`, { method: 'DELETE', auth: true }); toast('Rule deleted.', 'success'); loadRules(); }
          catch (error) { toast(messageFromError(error), 'error'); }
        });
      });
    } catch (error) {
      root.innerHTML = `<div class="panel empty-state">${escapeHTML(messageFromError(error))}</div>`;
    }
  }

  function childOptionsHTML(selected = 'ALL') {
    const options = [`<option value="ALL">All Children</option>`].concat((state.children || []).map((child) => `<option value="${escapeHTML(child.id)}" ${selected === child.id ? 'selected' : ''}>${escapeHTML(child.name)}</option>`));
    return options.join('');
  }

  function rulesHTML(rules) {
    return `<div class="feature-grid two-cols">
      <form id="ruleForm" class="panel form-panel wide-form">
        <h2>Add Parent Rule</h2>
        <label>Rule Type
          <select name="type" required>
            <option value="block">Block / Dangerous</option>
            <option value="warn">Warn Only</option>
            <option value="allow">Allow / Whitelist</option>
          </select>
        </label>
        <label>Apply To
          <select name="childId">${childOptionsHTML('ALL')}</select>
        </label>
        <label>Match Type
          <select name="matchType">
            <option value="domain">Domain + Subdomain</option>
            <option value="url">URL / Path Contains</option>
            <option value="keyword">Keyword Contains</option>
            <option value="category">Category</option>
          </select>
        </label>
        <label>Domain or Keyword
          <input 
            name="pattern" 
            placeholder="contoh: youtube.com / youtube.com/shorts / slot / Gambling" 
            required
          >
        </label>
        <label>Category
          <input name="category" placeholder="contoh: Games, Social Media, Gambling, Education">
        </label>
        <button class="btn primary" type="submit">Save Rule</button>
      </form>
      <div class="panel">
        <h2>Current Rules</h2>
        ${rulesListHTML(rules)}
      </div>
    </div>`;
  }

  function rulesListHTML(rules) {
    if (!Array.isArray(rules) || !rules.length) return '<div class="empty-state">Belum ada custom whitelist/blocklist.</div>';
    return `<div class="rule-list">${rules.map((rule) => {
      const childLabel = rule.childId === 'ALL' ? 'All Children' : (state.children || []).find((c) => c.id === rule.childId)?.name || rule.childId;
      return `<div class="rule-item ${rule.type}">
        <div><strong>${escapeHTML(rule.pattern)}</strong><small>${escapeHTML(rule.type)} • ${escapeHTML(rule.matchType)} • ${escapeHTML(rule.category || '-')} • ${escapeHTML(childLabel)}</small></div>
        <button class="btn ghost" data-delete-rule="${escapeHTML(rule.id)}">Delete</button>
      </div>`;
    }).join('')}</div>`;
  }

  async function loadRequests() {
    if (!requireUser()) return;
    const root = document.getElementById('requestsContent');
    const status = document.getElementById('requestStatusFilter')?.value || 'all';
    root.innerHTML = `<div class="loading">Loading requests...</div>`;
    try {
      const response = await api('/access-requests', { auth: true, params: { status } });
      const requests = normalizeResponse(response) || [];
      root.innerHTML = requestsHTML(requests);
      root.querySelectorAll('[data-request-decision]').forEach((button) => {
        button.addEventListener('click', async () => {
          try {
            await api(`/access-requests/${button.dataset.requestId}`, { method: 'PUT', auth: true, data: { decision: button.dataset.requestDecision } });
            toast(button.dataset.requestDecision === 'approve' ? 'Request approved.' : 'Request denied.', 'success');
            loadRequests();
          } catch (error) { toast(messageFromError(error), 'error'); }
        });
      });
    } catch (error) { root.innerHTML = `<div class="panel empty-state">${escapeHTML(messageFromError(error))}</div>`; }
  }

  function safeHost(url) {
    try { return new URL(url).hostname.replace(/^www\./, ''); }
    catch (_) { return String(url || '').replace(/^https?:\/\//, '').split('/')[0].replace(/^www\./, ''); }
  }

  function requestsHTML(requests) {
    if (!Array.isArray(requests) || !requests.length) {
      return '<div class="panel empty-state">No access request.</div>';
    }

    return `<div class="request-list">${requests.map((req) => {
      const status = req.status || 'pending';
      const statusKind = status === 'approved' ? 'positive' : status === 'denied' ? 'negative' : 'pending';
      const childName = req.child?.name || '-';
      const host = safeHost(req.url || '');
      const actionHTML = status === 'pending'
        ? `<div class="request-actions"><span class="status ${statusKind} request-status-badge">${escapeHTML(status)}</span><button class="btn primary" data-request-id="${escapeHTML(req.id)}" data-request-decision="approve">Allow</button><button class="btn danger" data-request-id="${escapeHTML(req.id)}" data-request-decision="deny">Deny</button></div>`
        : `<div class="request-actions"><span class="status ${statusKind} request-status-badge">${escapeHTML(status)}</span></div>`;
      return `<article class="request-card ${escapeHTML(status)}">
        <div class="request-icon">${status === 'approved' ? '✅' : status === 'denied' ? '⛔' : '🔔'}</div>
        <div class="request-main">
          <div class="request-title-row"><strong>${escapeHTML(childName)} requested access</strong></div>
          <p class="request-url" title="${escapeHTML(req.url)}">${escapeHTML(req.url)}</p>
          <div class="request-meta">
            <span>🌐 ${escapeHTML(host || 'unknown host')}</span>
            <span>🕒 ${escapeHTML(formatDateIndonesia(req.createdAt))}</span>
            ${req.reason ? `<span>📝 ${escapeHTML(req.reason)}</span>` : ''}
          </div>
        </div>
        ${actionHTML}
      </article>`;
    }).join('')}</div>`;
  }

  async function loadSchedule() {
    if (!requireUser()) return;
    const root = document.getElementById('scheduleContent');
    root.innerHTML = `<div class="loading">Loading schedule...</div>`;
    try {
      const children = await refreshChildren();
      const selected = state.selectedChildId || children[0]?.id || '';
      if (!selected) { root.innerHTML = '<div class="panel empty-state">Tambah child dulu sebelum mengatur jadwal.</div>'; return; }
      const [response, listResponse] = await Promise.all([
        api(`/schedule/${selected}`, { auth: true }),
        api('/schedules', { auth: true }).catch(() => ({ data: [] })),
      ]);
      const schedule = normalizeResponse(response) || { enabled:false, start:'08:00', end:'21:00', days:[] };
      const scheduleList = normalizeResponse(listResponse) || [];
      root.innerHTML = scheduleHTML(selected, schedule, scheduleList);
      root.querySelector('#scheduleChildSelect')?.addEventListener('change', (e) => { setSelectedChild(e.target.value); loadSchedule(); });
      root.querySelector('#scheduleForm')?.addEventListener('submit', async (event) => {
        event.preventDefault();
        const fd = new FormData(event.currentTarget);
        const days = [...root.querySelectorAll('[name="scheduleDay"]:checked')].map((i) => i.value);
        try {
          await api(`/schedule/${root.querySelector('#scheduleChildSelect').value}`, { method:'PUT', auth:true, data: { enabled: fd.get('enabled') === 'on', start: fd.get('start'), end: fd.get('end'), days } });
          toast('Schedule saved.', 'success');
          loadSchedule();
        } catch (error) { toast(messageFromError(error), 'error'); }
      });
    } catch (error) { root.innerHTML = `<div class="panel empty-state">${escapeHTML(messageFromError(error))}</div>`; }
  }

  function scheduleHTML(childId, schedule, scheduleList = []) {
    const days = schedule.days || [];
    const labels = [['mon','Mon'],['tue','Tue'],['wed','Wed'],['thu','Thu'],['fri','Fri'],['sat','Sat'],['sun','Sun']];
    const childName = (state.children || []).find((child) => child.id === childId)?.name || 'Selected child';
    return `<form id="scheduleForm" class="panel form-panel wide-form schedule-panel">
      <div class="schedule-head">
        <div>
          <p class="eyebrow">Internet Schedule</p>
          <h2>Time Control for ${escapeHTML(childName)}</h2>
          <p class="muted">Atur kapan anak boleh browsing. Di luar jadwal, extension akan menampilkan halaman blocked.</p>
        </div>
        <label class="toggle-card">
          <input type="checkbox" name="enabled" ${schedule.enabled ? 'checked' : ''}>
          <span class="toggle-switch" aria-hidden="true"></span>
          <span class="toggle-text"><strong>Enable schedule blocking</strong><small>${schedule.enabled ? 'Aktif' : 'Nonaktif'}</small></span>
        </label>
      </div>
      <label>Child<select id="scheduleChildSelect">${(state.children || []).map((child) => `<option value="${escapeHTML(child.id)}" ${child.id === childId ? 'selected' : ''}>${escapeHTML(child.name)}</option>`).join('')}</select></label>
      <div class="two-field-row"><label>Start Time<input type="time" name="start" value="${escapeHTML(schedule.start || '08:00')}"></label><label>End Time<input type="time" name="end" value="${escapeHTML(schedule.end || '21:00')}"></label></div>
      <div class="allowed-days-block"><strong>Allowed Days</strong><div class="day-chip-grid">${labels.map(([value,label]) => `<label class="day-chip"><input type="checkbox" name="scheduleDay" value="${value}" ${days.includes(value) ? 'checked' : ''}> <span>${label}</span></label>`).join('')}</div></div>
      <p class="notice schedule-notice">Kalau schedule aktif, extension akan menandai website sebagai <strong>Schedule Lock</strong> di luar hari/jam yang diizinkan.</p>
      <button class="btn primary" type="submit">Save Schedule</button>
    </form>
    ${scheduleListHTML(scheduleList)}`;
  }

  function scheduleListHTML(items) {
    if (!Array.isArray(items) || !items.length) return '';
    const dayLabel = { mon:'Mon', tue:'Tue', wed:'Wed', thu:'Thu', fri:'Fri', sat:'Sat', sun:'Sun' };
    return `<section class="panel schedule-list-panel">
      <div class="section-title-row"><div><p class="eyebrow">Saved Schedules</p><h2>Daftar jadwal yang sudah diterapkan</h2></div></div>
      <div class="schedule-list-grid">${items.map((item) => {
        const child = item.child || {};
        const s = item.schedule || {};
        const days = Array.isArray(s.days) ? s.days.map((d) => dayLabel[d] || d).join(', ') : '-';
        return `<article class="schedule-summary-card ${s.enabled ? 'enabled' : 'disabled'}">
          <div><strong>${escapeHTML(child.name || 'Child')}</strong><span class="status ${s.enabled ? 'positive' : 'pending'}">${s.enabled ? 'Enabled' : 'Disabled'}</span></div>
          <p>${escapeHTML(s.start || '08:00')} - ${escapeHTML(s.end || '21:00')}</p>
          <small>${escapeHTML(days || '-')}</small>
        </article>`;
      }).join('')}</div>
    </section>`;
  }

  async function loadReport() {
    if (!requireUser()) return;
    const root = document.getElementById('reportContent');
    const dateInput = document.getElementById('reportDate');
    if (dateInput && !dateInput.value) dateInput.value = new Date().toISOString().slice(0,10);
    try {
      if (!state.children?.length) await refreshChildren();
      const childSelect = document.getElementById('reportChildFilter');
      if (childSelect) {
        const current = childSelect.value || state.selectedChildId || 'ALL';
        childSelect.innerHTML = childOptionsHTML(current);
        childSelect.value = [...childSelect.options].some((o) => o.value === current) ? current : 'ALL';
      }
      const childId = childSelect?.value || 'ALL';
      const period = document.getElementById('reportPeriod')?.value || 'daily';
      const date = dateInput?.value || new Date().toISOString().slice(0,10);
      const d = new Date(date);
      const params = { period, selectedDate: date, year: d.getFullYear(), month: d.getMonth()+1, date: d.getDate() };
      const response = await api(`/report/${childId}`, { auth: true, params });
      const report = normalizeResponse(response) || {};
      root.innerHTML = reportHTML(report);
    } catch (error) { root.innerHTML = `<div class="panel empty-state">${escapeHTML(messageFromError(error))}</div>`; }
  }

  function reportHTML(report) {
    const cats = report.categories || {};
    const hosts = report.topHosts || {};
    const riskyHosts = report.topRiskyHosts || {};
    const recent = report.recent || [];
    const reportDate = document.getElementById('reportDate')?.value || new Date().toISOString().slice(0, 10);
    const reportPeriod = document.getElementById('reportPeriod')?.value || 'daily';
    const childValue = document.getElementById('reportChildFilter')?.value || 'ALL';
    const childLabel = childValue === 'ALL' ? 'All Children' : ((state.children || []).find((child) => child.id === childValue)?.name || 'Selected Child');
    return `<div class="report-screen-only">
      <section class="card-grid report-cards">
        ${statCard('📌','Total Visit', report.total || 0, '', 'all')}
        ${statCard('✅','Safe', report.safe || 0, 'positive', 'positive')}
        ${statCard('⚠️','Risky / Blocked', report.danger || 0, 'negative', 'negative')}
        ${statCard('⭐','Safety Score', `${report.safePercent ?? 100}%`, 'positive', 'all')}
      </section>
      <section class="report-summary-grid report-print-area">
        <div class="panel"><h2>Category Breakdown</h2>${Object.keys(cats).length ? `<div class="category-list">${Object.entries(cats).map(([k,v]) => `<div><span>${escapeHTML(k)}</span><strong>${v}</strong></div>`).join('')}</div>` : '<div class="empty-state">No category data.</div>'}</div>
        <div class="panel"><h2>Most Visited Hosts</h2>${Object.keys(hosts).length ? `<div class="category-list">${Object.entries(hosts).map(([k,v]) => `<div><span>${escapeHTML(k)}</span><strong>${v}</strong></div>`).join('')}</div>` : '<div class="empty-state">No host data.</div>'}</div>
        <div class="panel"><h2>Top Risky / Blocked Hosts</h2>${Object.keys(riskyHosts).length ? `<div class="category-list">${Object.entries(riskyHosts).map(([k,v]) => `<div><span>${escapeHTML(k)}</span><strong>${v}</strong></div>`).join('')}</div>` : '<div class="empty-state">No risky host data.</div>'}</div>
      </section>
      <section class="panel report-log-section"><h2>Recent Logs in Report</h2>${logsTableHTML(recent)}</section>
    </div>
    ${printReportHTML({ report, cats, hosts, riskyHosts, recent, reportDate, reportPeriod, childLabel })}`;
  }

  function printReportHTML({ report, cats, hosts, riskyHosts = {}, recent, reportDate, reportPeriod, childLabel }) {
    return `<article class="print-report-document" aria-hidden="true">
      <header class="print-report-header">
        <div>
          <h1>Snailly Kids Activity Report</h1>
          <p>Report period: ${escapeHTML(reportPeriod)} • Date: ${escapeHTML(reportDate)} • Child: ${escapeHTML(childLabel)}</p>
        </div>
        <strong>Snailly Kids</strong>
      </header>
      <section class="print-summary-grid">
        <div><span>Total Visit</span><strong>${escapeHTML(report.total || 0)}</strong></div>
        <div><span>Safe</span><strong>${escapeHTML(report.safe || 0)}</strong></div>
        <div><span>Risky / Blocked</span><strong>${escapeHTML(report.danger || 0)}</strong></div>
        <div><span>Safety Score</span><strong>${escapeHTML(report.safePercent ?? 100)}%</strong></div>
      </section>
      <section class="print-two-cols">
        <div>
          <h2>Category Breakdown</h2>
          ${Object.keys(cats).length ? `<table class="print-mini-table"><tbody>${Object.entries(cats).map(([k,v]) => `<tr><td>${escapeHTML(k)}</td><td>${escapeHTML(v)}</td></tr>`).join('')}</tbody></table>` : '<p>No category data.</p>'}
        </div>
        <div>
          <h2>Most Visited Hosts</h2>
          ${Object.keys(hosts).length ? `<table class="print-mini-table"><tbody>${Object.entries(hosts).map(([k,v]) => `<tr><td>${escapeHTML(k)}</td><td>${escapeHTML(v)}</td></tr>`).join('')}</tbody></table>` : '<p>No host data.</p>'}
        </div>
      </section>
      <section>
        <h2>Log Activity</h2>
        ${printLogsTableHTML(recent)}
      </section>
      <footer class="print-footer">Generated locally from Snailly Kids dashboard.</footer>
    </article>`;
  }

  function printLogsTableHTML(items) {
    if (!Array.isArray(items) || items.length === 0) return '<p>No log activity available.</p>';
    const shorten = (text, max = 95) => {
      const value = String(text || '');
      return value.length > max ? `${value.slice(0, max)}...` : value;
    };
    return `<table class="print-log-table">
      <thead><tr><th>No</th><th>Website / URL</th><th>Date</th><th>Child</th><th>Category</th><th>Status</th></tr></thead>
      <tbody>${items.map((item, index) => {
        const [label] = statusOf(item);
        const host = safeHost(item.url || 'unknown');
        return `<tr>
          <td>${index + 1}</td>
          <td><span class="print-url-host">${escapeHTML(host || 'unknown host')}</span><span class="print-url-full">${escapeHTML(shorten(item.url || 'unknown'))}</span></td>
          <td>${escapeHTML(formatDateIndonesia(item.createdAt))}</td>
          <td>${escapeHTML(item.child?.name || '-')}</td>
          <td>${escapeHTML(categoryOfLog(item))}</td>
          <td>${escapeHTML(label)}</td>
        </tr>`;
      }).join('')}</tbody>
    </table>`;
  }

  function printReportOnly() {
    const reportEl = document.querySelector('.print-report-document');
    if (!reportEl) {
      toast('Report belum siap untuk diprint.', 'error');
      return;
    }
    const iframe = document.createElement('iframe');
    iframe.style.position = 'fixed';
    iframe.style.right = '0';
    iframe.style.bottom = '0';
    iframe.style.width = '0';
    iframe.style.height = '0';
    iframe.style.border = '0';
    iframe.setAttribute('aria-hidden', 'true');
    document.body.appendChild(iframe);
    const doc = iframe.contentWindow.document;
    doc.open();
    doc.write(`<!doctype html><html><head><meta charset="utf-8"><title>Snailly Kids Activity Report</title><style>
      @page { size: A4; margin: 12mm; }
      * { box-sizing: border-box; }
      body { margin: 0; font-family: Arial, sans-serif; color: #182416; background: #fff; font-size: 10pt; }
      .print-report-document { display: block !important; width: 100%; }
      .print-report-header { display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; border-bottom: 2px solid #4e773f; padding-bottom: 10px; margin-bottom: 14px; }
      .print-report-header h1 { margin: 0 0 5px; color: #25411f; font-size: 20pt; line-height: 1.15; }
      .print-report-header p { margin: 0; color: #4d5b49; font-size: 9.5pt; }
      .print-report-header strong { color: #4e773f; white-space: nowrap; font-size: 10pt; }
      .print-summary-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 8px; margin: 12px 0 14px; }
      .print-summary-grid div { border: 1px solid #d9e4d2; border-radius: 8px; padding: 8px 9px; background: #fbfdf8; }
      .print-summary-grid span { display: block; color: #5f6b5a; font-size: 8.5pt; margin-bottom: 4px; }
      .print-summary-grid strong { display: block; color: #25411f; font-size: 17pt; line-height: 1; }
      .print-two-cols { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 16px; break-inside: avoid; }
      h2 { margin: 0 0 7px; color: #25411f; font-size: 12pt; line-height: 1.2; }
      .print-mini-table, .print-log-table { width: 100%; border-collapse: collapse; table-layout: fixed; }
      .print-mini-table td, .print-log-table th, .print-log-table td { border: 1px solid #d9e4d2; padding: 6px 7px; vertical-align: top; line-height: 1.25; }
      .print-mini-table td { font-size: 8.8pt; }
      .print-mini-table td:first-child { width: 78%; }
      .print-mini-table td:last-child { width: 22%; text-align: right; font-weight: 700; }
      .print-log-table { margin-top: 6px; }
      .print-log-table th { background: #eef6e7; color: #25411f; font-weight: 700; font-size: 8.2pt; text-align: left; }
      .print-log-table td { font-size: 7.8pt; word-break: break-word; }
      .print-log-table th:nth-child(1), .print-log-table td:nth-child(1) { width: 7%; text-align: center; }
      .print-log-table th:nth-child(2), .print-log-table td:nth-child(2) { width: 36%; }
      .print-log-table th:nth-child(3), .print-log-table td:nth-child(3) { width: 20%; }
      .print-log-table th:nth-child(4), .print-log-table td:nth-child(4) { width: 11%; }
      .print-log-table th:nth-child(5), .print-log-table td:nth-child(5) { width: 15%; }
      .print-log-table th:nth-child(6), .print-log-table td:nth-child(6) { width: 11%; }
      .print-url-host { display: block; color: #25411f; font-weight: 700; margin-bottom: 2px; }
      .print-url-full { display: block; color: #4d5b49; font-size: 7.2pt; }
      .print-footer { margin-top: 12px; padding-top: 7px; border-top: 1px solid #d9e4d2; color: #5f6b5a; font-size: 7.5pt; }
    </style></head><body>${reportEl.outerHTML}</body></html>`);
    doc.close();
    setTimeout(() => {
      iframe.contentWindow.focus();
      iframe.contentWindow.print();
      setTimeout(() => iframe.remove(), 800);
    }, 250);
  }

  async function loadSetting() {
    if (!requireUser()) return;
    try {
      const response = await api('/auth/me', { auth: true });
      state.currentUser = normalizeResponse(response);
      writeJSON('snailly_current_user', state.currentUser);
      const form = document.getElementById('settingForm');
      if (form) {
        form.elements.name.value = state.currentUser?.name || state.user?.name || '';
        form.elements.email.value = state.currentUser?.email || state.user?.email || '';
      }
    } catch (error) {
      toast(messageFromError(error), 'error');
    }
  }

  document.getElementById('settingForm')?.addEventListener('submit', async (event) => {
    event.preventDefault();
    if (!requireUser()) return;

    const formEl = event.currentTarget;
    const form = new FormData(formEl);
    const newPassword = String(form.get('newPassword') || '');
    const confirmPassword = String(form.get('confirmPassword') || '');

    if (newPassword !== confirmPassword) {
      toast('Password tidak sama.', 'error');
      return;
    }
    const id = state.currentUser?.id || state.user?.id;
    if (!id) {
      toast('Current user data not loaded.', 'error');
      return;
    }
    try {
      const response = await api(`/profile/${id}`, {
        method: 'PUT',
        auth: true,
        data: {
          name: form.get('name'),
          email: form.get('email'),
          oldPassword: form.get('oldPassword'),
          newPassword,
          confirmPassword,
        },
      });
      const updated = normalizeResponse(response);
      const nextUser = { ...state.user, ...updated, accessToken: state.user.accessToken };
      setUser(nextUser);
      state.currentUser = updated;
      writeJSON('snailly_current_user', updated);
      toast('Profile updated.', 'success');
      formEl.elements.oldPassword.value = '';
      formEl.elements.newPassword.value = '';
      formEl.elements.confirmPassword.value = '';
      loadSetting();
    } catch (error) {
      toast(messageFromError(error), 'error');
    }
  });

  function renderBlocked() {
    const params = new URL(window.location.href).searchParams;

    function unwrapBlockedUrl(value) {
      let current = String(value || '');
      for (let i = 0; i < 5; i++) {
        try {
          const parsed = new URL(current);
          if (!parsed.pathname.toLowerCase().includes('/snailly/blocked')) break;
          const next = parsed.searchParams.get('url');
          if (!next || next === current) break;
          current = next;
        } catch (_) {
          break;
        }
      }
      return current;
    }

    const url = unwrapBlockedUrl(params.get('url') || '');
    const reason = params.get('reason') || params.get('category') || '';
    const childId = params.get('childId') || '';
    const requestToken = params.get('token') || '';
    const blockedUrl = document.getElementById('blockedUrl');
    const reasonEl = document.getElementById('blockedReason');
    if (blockedUrl) blockedUrl.textContent = url;
    if (reasonEl) reasonEl.textContent = reason ? `Reason: ${reason}` : '';
    const button = document.getElementById('requestAccessButton');
    if (button) {
      button.onclick = async () => {
        if (!url || !requestToken || !childId) {
          toast('Request belum bisa dikirim karena data extension tidak lengkap.', 'error');
          return;
        }
        button.disabled = true;
        try {
          const apiUrl = new URL('/api/snailly/proxy', window.location.origin);
          apiUrl.searchParams.set('path', '/access-requests');
          const response = await fetch(apiUrl.toString(), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-Snailly-Authorization': `Bearer ${requestToken}` },
            body: JSON.stringify({ childId, url, reason: reason || 'Child requested access from blocked page.' })
          });
          const json = await response.json().catch(() => ({}));
          if (!response.ok || json.ok === false) throw new Error(json.message || 'Request failed.');
          toast(json.message || 'Request sent to parent.', 'success');
          button.textContent = 'Request Sent ✅';
        } catch (error) {
          toast(messageFromError(error), 'error');
          button.disabled = false;
        }
      };
    }

    async function checkApprovedAndRedirect() {
      if (!url || !requestToken || !childId) return;
      try {
        const apiUrl = new URL('/api/snailly/proxy', window.location.origin);
        apiUrl.searchParams.set('path', '/policy-check');
        const response = await fetch(apiUrl.toString(), {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'X-Snailly-Authorization': `Bearer ${requestToken}` },
          body: JSON.stringify({ childId, url, title: 'Blocked page policy check', source: 'blocked_page_policy_check' })
        });
        const json = await response.json().catch(() => ({}));
        if (response.ok && json.ok !== false && json.blocked === false) {
          toast('Access updated. Redirecting...', 'success');
          setTimeout(() => { window.location.href = url; }, 500);
        }
      } catch (_) {}
    }
    setTimeout(checkApprovedAndRedirect, 1000);
    const timer = setInterval(checkApprovedAndRedirect, 3000);
    window.addEventListener('beforeunload', () => clearInterval(timer));
  }


  const today = new Date();
  const dateInput = document.getElementById('logDate');
  const monthInput = document.getElementById('logMonth');
  const reportDateInput = document.getElementById('reportDate');
  if (dateInput && !dateInput.value) dateInput.value = today.toISOString().slice(0, 10);
  if (monthInput && !monthInput.value) monthInput.value = today.toISOString().slice(0, 7);
  if (reportDateInput && !reportDateInput.value) reportDateInput.value = today.toISOString().slice(0, 10);
  syncLogInputs();
  bootstrapSession().finally(() => {
    renderGlobalChildFilter();
    renderPage(currentPage());
  });
})();
