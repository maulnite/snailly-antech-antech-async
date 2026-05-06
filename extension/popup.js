const DEFAULTS = {
  backendBase: 'http://localhost/snailly',
  token: '',
  childId: '',
  enabled: true,
  blockDangerous: false,
  ignoreLocalhost: true,
  children: [],
  lastTracked: null,
  lastError: ''
};

const $ = (id) => document.getElementById(id);

function normalizeBase(base) {
  return String(base || DEFAULTS.backendBase).replace(/\/+$/, '');
}

function storageGet() {
  return new Promise((resolve) => chrome.storage.local.get(DEFAULTS, (items) => resolve({ ...DEFAULTS, ...items })));
}

function storageSet(data) {
  return new Promise((resolve) => chrome.storage.local.set(data, resolve));
}

function setStatus(title, text, type = '') {
  $('statusTitle').textContent = title;
  $('statusText').textContent = text;
  $('statusCard').className = `status-card ${type}`.trim();
}

function updatePowerButton(settings) {
  const btn = $('powerButton');
  if (!btn) return;
  const on = Boolean(settings.enabled);
  btn.textContent = on ? '🟢 Tracking ON — Click to Turn OFF' : '🔴 Tracking OFF — Click to Turn ON';
  btn.className = `power-btn ${on ? 'on' : 'off'}`;
}

function renderChildren(children, selectedId = '') {
  const select = $('childSelect');
  if (!Array.isArray(children) || children.length === 0) {
    select.innerHTML = '<option value="">Belum ada child. Tambah dulu di web dashboard.</option>';
    return;
  }
  select.innerHTML = children.map((child) => `<option value="${escapeAttr(child.id)}">${escapeHTML(child.name || child.id)}</option>`).join('');
  select.value = selectedId || children[0]?.id || '';
}

function escapeHTML(value) {
  return String(value ?? '').replace(/[&<>'"]/g, (c) => ({ '&':'&amp;', '<':'&lt;', '>':'&gt;', "'":'&#039;', '"':'&quot;' }[c]));
}
function escapeAttr(value) { return escapeHTML(value); }

async function api(path, { method = 'GET', data = null, token = '' } = {}) {
  const base = normalizeBase($('backendBase').value);
  const url = `${base}/api/proxy.php?path=${encodeURIComponent(path)}`;
  const headers = { Accept: 'application/json' };
  if (data !== null) headers['Content-Type'] = 'application/json';
  if (token) headers['X-Snailly-Authorization'] = `Bearer ${token}`;
  const response = await fetch(url, {
    method,
    headers,
    body: data !== null ? JSON.stringify(data) : null
  });
  const text = await response.text();
  let json = {};
  try { json = text ? JSON.parse(text) : {}; }
  catch (_) { json = { ok: false, message: text || 'Invalid backend response.' }; }
  if (!response.ok || json.ok === false) {
    throw new Error(json.message || `Request failed (${response.status})`);
  }
  return json;
}

function dataOf(json) { return json?.data ?? json; }

async function saveForm(extra = {}) {
  const selected = $('childSelect').value;
  const payload = {
    backendBase: normalizeBase($('backendBase').value),
    childId: selected,
    enabled: $('enabled').checked,
    blockDangerous: $('blockDangerous').checked,
    ignoreLocalhost: $('ignoreLocalhost').checked,
    ...extra
  };
  await storageSet(payload);
  chrome.runtime.sendMessage({ type: 'refreshBadge' });
  chrome.runtime.sendMessage({ type: 'syncStatus' });
  updateStatus(await storageGet());
}

function updateStatus(settings) {
  updatePowerButton(settings);
  if (!settings.enabled) {
    setStatus('Tracking OFF', 'Klik tombol ON/OFF atau centang Tracking ON kalau mau mulai mencatat URL.', 'error');
    return;
  }
  if (!settings.token) {
    setStatus('Belum login', 'Login pakai akun parent dari web lokal dulu.', '');
    return;
  }
  if (!settings.childId) {
    setStatus('Child belum dipilih', 'Pilih child yang aktivitas browsernya mau dicatat.', '');
    return;
  }
  setStatus('Tracking aktif', 'URL dari browser ini akan masuk ke dashboard lokal.', 'ready');
}

async function refreshChildren(tokenFromArg = '') {
  const settings = await storageGet();
  const token = tokenFromArg || settings.token;
  if (!token) throw new Error('Token kosong. Login dulu dari extension popup.');
  const response = await api('/child', { token });
  const children = dataOf(response) || [];
  const selectedId = settings.childId || children[0]?.id || '';
  renderChildren(children, selectedId);
  await saveForm({ token, children, childId: $('childSelect').value || selectedId });
  return children;
}

async function init() {
  const settings = await storageGet();
  $('backendBase').value = normalizeBase(settings.backendBase);
  $('enabled').checked = Boolean(settings.enabled);
  $('blockDangerous').checked = Boolean(settings.blockDangerous);
  $('ignoreLocalhost').checked = settings.ignoreLocalhost !== false;
  renderChildren(settings.children || [], settings.childId);
  updateStatus(settings);
  renderLast(settings);
}

function renderLast(settings) {
  const last = settings.lastTracked;
  if (!last) {
    $('lastTracked').textContent = 'Belum ada.';
  } else {
    $('lastTracked').textContent = `${last.label || '-'} • ${new Date(last.at).toLocaleString('id-ID')} • ${last.url}`;
  }
  $('lastError').textContent = settings.lastError ? `Error: ${settings.lastError}` : '';
}


$('logoutButton')?.addEventListener('click', async () => {
  const settings = await storageGet();
  try {
    if (settings.token) {
      await api('/auth/logout', { method: 'POST', token: settings.token });
    }
  } catch (_) {
    // Tetap clear token lokal walaupun backend sedang tidak aktif.
  }
  await storageSet({ token: '', parentUser: null, childId: '', children: [], enabled: false, lastError: '', lastTracked: null });
  renderChildren([], '');
  $('enabled').checked = false;
  updatePowerButton({ enabled: false });
  setStatus('Tracker logout', 'Token extension sudah dihapus dan tracking dimatikan.', '');
  try { await chrome.runtime.sendMessage({ type: 'refreshBadge' }); } catch (_) {}
});

$('loginButton').addEventListener('click', async () => {
  $('loginButton').disabled = true;
  try {
    setStatus('Login...', 'Menghubungi backend lokal.', '');
    const login = await api('/auth/tracker-login', {
      method: 'POST',
      data: {
        email: $('email').value.trim(),
        password: $('password').value,
        registrationToken: 'chrome-extension-local'
      }
    });
    const user = dataOf(login);
    const token = user.accessToken;
    await storageSet({ token, parentUser: user, backendBase: normalizeBase($('backendBase').value) });
    await refreshChildren(token);
    setStatus('Tracker login berhasil', 'Token extension khusus sudah dibuat. Pilih child lalu Save Config.', 'ready');
  } catch (error) {
    setStatus('Login gagal', error.message || String(error), 'error');
    await storageSet({ lastError: error.message || String(error) });
  } finally {
    $('loginButton').disabled = false;
  }
});

$('powerButton')?.addEventListener('click', async () => {
  const settings = await storageGet();
  $('enabled').checked = !settings.enabled;
  await saveForm({ enabled: $('enabled').checked });
});

$('saveButton').addEventListener('click', async () => {
  await saveForm();
  setStatus('Config tersimpan', 'Extension siap melakukan tracking sesuai child yang dipilih.', 'ready');
});

$('refreshButton').addEventListener('click', async () => {
  $('refreshButton').disabled = true;
  try {
    await refreshChildren();
    setStatus('Child diperbarui', 'Data child berhasil diambil dari web lokal.', 'ready');
  } catch (error) {
    setStatus('Refresh gagal', error.message || String(error), 'error');
    await storageSet({ lastError: error.message || String(error) });
  } finally {
    $('refreshButton').disabled = false;
  }
});

$('testButton').addEventListener('click', async () => {
  await saveForm();
  $('testButton').disabled = true;
  try {
    const result = await chrome.runtime.sendMessage({ type: 'trackCurrentTab' });
    if (result?.ok) {
      setStatus('Test berhasil', result.message || 'Tab saat ini berhasil dicatat.', 'ready');
    } else {
      setStatus('Test belum berhasil', result?.message || 'Tab tidak dicatat.', 'error');
    }
    renderLast(await storageGet());
  } catch (error) {
    setStatus('Test error', error.message || String(error), 'error');
  } finally {
    $('testButton').disabled = false;
  }
});

['backendBase', 'childSelect', 'enabled', 'blockDangerous', 'ignoreLocalhost'].forEach((id) => {
  $(id).addEventListener('change', () => saveForm());
});

chrome.storage.onChanged.addListener(async () => renderLast(await storageGet()));
init();
