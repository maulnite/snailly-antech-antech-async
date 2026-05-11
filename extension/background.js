const DEFAULTS = {
  backendBase: 'https://snailly.test',
  token: '',
  childId: '',
  enabled: true,
  blockDangerous: false,
  ignoreLocalhost: true,
  lastTracked: null,
  lastError: ''
};

const recentByTab = new Map();
let lastStatusReportAt = 0;
let lastStatusReportKey = '';
const STATUS_REPORT_MIN_INTERVAL_MS = 60000;
const DUPLICATE_WINDOW_MS = 30000;

function normalizeBase(base) {
  return String(base || DEFAULTS.backendBase).replace(/\/+$/, '');
}

function isPrivateOrLocalHost(hostname) {
  const host = String(hostname || '').toLowerCase();
  if (['localhost', '127.0.0.1', '::1'].includes(host)) return true;
  if (/^192\.168\./.test(host)) return true;
  if (/^10\./.test(host)) return true;
  const match172 = host.match(/^172\.(\d+)\./);
  if (match172) {
    const second = Number(match172[1]);
    return second >= 16 && second <= 31;
  }
  return false;
}

function getBackendOrigin(settings) {
  try {
    return new URL(normalizeBase(settings.backendBase)).origin;
  } catch (_) {
    return '';
  }
}

function isSnaillyInternalUrl(url, settings = {}) {
  try {
    const parsed = new URL(url);
    const backendOrigin = getBackendOrigin(settings);
    const path = parsed.pathname.toLowerCase();
    const isSnaillyPath = path.includes('/snailly') || path.includes('/api/snailly') || path.includes('/blocked');
    if (!isSnaillyPath) return false;
    if (backendOrigin && parsed.origin === backendOrigin) return true;
    return isPrivateOrLocalHost(parsed.hostname);
  } catch (_) {
    return false;
  }
}

function buildBlockedPageUrl(settings, targetUrl, policy = {}) {
  const base = normalizeBase(settings.backendBase)
    .replace(/\/snailly\/public$/i, '')
    .replace(/\/snailly$/i, '');
  const blockedPage = new URL(`${base}/snailly/blocked`);
  blockedPage.searchParams.set('url', targetUrl);
  blockedPage.searchParams.set('childId', settings.childId);
  blockedPage.searchParams.set('token', settings.token);
  if (policy.category) blockedPage.searchParams.set('category', policy.category);
  if (policy.reason) blockedPage.searchParams.set('reason', policy.reason);
  return blockedPage.toString();
}

async function getSettings() {
  return new Promise((resolve) => {
    chrome.storage.local.get(DEFAULTS, (items) => {
      resolve({ ...DEFAULTS, ...items, backendBase: normalizeBase(items.backendBase) });
    });
  });
}

async function reportTrackerStatus(settings, options = {}) {
  if (!settings?.token || !settings?.childId) return;

  const force = Boolean(options.force);
  const now = Date.now();
  const statusKey = [
    normalizeBase(settings.backendBase),
    settings.childId,
    Boolean(settings.enabled),
    Boolean(settings.blockDangerous)
  ].join('|');

  if (!force && statusKey === lastStatusReportKey && now - lastStatusReportAt < STATUS_REPORT_MIN_INTERVAL_MS) {
    return;
  }

  lastStatusReportKey = statusKey;
  lastStatusReportAt = now;

  try {
    await fetch(`${normalizeBase(settings.backendBase)}/api/snailly/proxy?path=${encodeURIComponent(`/tracker-status/${settings.childId}`)}`, {
      method: 'PUT',
      headers: {
        'Content-Type': 'application/json',
        'X-Snailly-Authorization': `Bearer ${settings.token}`
      },
      body: JSON.stringify({
        enabled: Boolean(settings.enabled),
        blockDangerous: Boolean(settings.blockDangerous)
      })
    });
  } catch (_) {
    // Status sync is best-effort. Tracking should continue even if this fails.
  }
}

async function recheckActiveTabs() {
  const settings = await getSettings();
  if (!settings.enabled || !settings.blockDangerous || !settings.token || !settings.childId) return;
  chrome.tabs.query({ active: true, currentWindow: true }, (tabs) => {
    if (chrome.runtime.lastError) return;
    for (const tab of tabs || []) trackTab(tab, 'policy_recheck');
  });
}

function ensurePolicyAlarm() {
  if (!chrome.alarms) return;
  chrome.alarms.create('snaillyPolicyRecheck', { periodInMinutes: 1 });
}

async function setBadge() {
  const settings = await getSettings();
  if (!settings.enabled) {
    chrome.action.setBadgeText({ text: 'OFF' });
    chrome.action.setBadgeBackgroundColor({ color: '#777777' });
    return;
  }
  if (!settings.token || !settings.childId) {
    chrome.action.setBadgeText({ text: 'SET' });
    chrome.action.setBadgeBackgroundColor({ color: '#E09F3E' });
    return;
  }
  chrome.action.setBadgeText({ text: 'ON' });
  chrome.action.setBadgeBackgroundColor({ color: '#29A36A' });
}

function isTrackableUrl(url, settings) {
  if (!url || !/^https?:\/\//i.test(url)) return false;
  if (isSnaillyInternalUrl(url, settings)) return false;
  if (settings.ignoreLocalhost) {
    try {
      const host = new URL(url).hostname.toLowerCase();
      if (['localhost', '127.0.0.1', '::1'].includes(host)) return false;
    } catch (_) {
      return false;
    }
  }
  return true;
}

function isRecentDuplicate(tabId, url) {
  const now = Date.now();
  const key = `${tabId}:${url}`;
  const last = recentByTab.get(key) || 0;
  if (now - last < DUPLICATE_WINDOW_MS) return true;
  recentByTab.set(key, now);
  for (const [itemKey, itemTime] of recentByTab.entries()) {
    if (now - itemTime > 120000) recentByTab.delete(itemKey);
  }
  return false;
}

async function callBackend(settings, path, body) {
  const endpoint = path.startsWith('/api/')
    ? `${normalizeBase(settings.backendBase)}${path}`
    : `${normalizeBase(settings.backendBase)}/api/snailly/proxy?path=${encodeURIComponent(path)}`;

  const response = await fetch(endpoint, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-Snailly-Authorization': `Bearer ${settings.token}`
    },
    body: JSON.stringify(body)
  });

  const text = await response.text();
  let json = {};
  try { json = text ? JSON.parse(text) : {}; }
  catch (_) { json = { ok: false, message: text || 'Invalid response from backend.' }; }
  if (!response.ok || json.ok === false) throw new Error(json.message || `Backend error ${response.status}`);
  return json;
}

async function trackTab(tab, reason = 'tab_event') {
  const settings = await getSettings();
  await setBadge();

  if (!settings.enabled) return { ok: false, skipped: true, message: 'Tracking disabled.' };
  if (!settings.token || !settings.childId) return { ok: false, skipped: true, message: 'Extension is not configured.' };

  const url = tab?.url || '';
  if (!isTrackableUrl(url, settings)) return { ok: true, skipped: true, message: 'Internal Snailly/local URL ignored.' };

  const policyOnly = reason === 'policy_recheck';
  if (!settings.blockDangerous && isRecentDuplicate(tab.id || 0, url)) {
    return { ok: true, skipped: true, message: 'Duplicate ignored.' };
  }

  try {
    const body = {
      childId: settings.childId,
      url,
      title: tab?.title || '',
      source: `chrome_extension:${reason}`
    };

    const json = policyOnly
      ? await callBackend(settings, '/policy-check', body)
      : await callBackend(settings, '/api/snailly/track', body);

    await chrome.storage.local.set({
      lastTracked: {
        url,
        title: tab?.title || '',
        at: new Date().toISOString(),
        label: json.label || (json.action === 'warn' ? 'peringatan' : (json.blocked ? 'bahaya' : 'aman')),
        action: json.action || (json.blocked ? 'block' : 'allow'),
        blocked: json.blocked === true,
        duplicate: Boolean(json.duplicate),
        policyOnly,
        category: json.category || '',
        reason: json.reason || ''
      },
      lastError: ''
    });

    if (settings.blockDangerous && json.blocked === true && tab?.id && !isSnaillyInternalUrl(url, settings)) {
      const blockUrl = buildBlockedPageUrl(settings, url, json);
      await chrome.tabs.update(tab.id, { url: blockUrl });
    }

    return json;
  } catch (error) {
    await chrome.storage.local.set({ lastError: error.message || String(error) });
    return { ok: false, message: error.message || String(error) };
  }
}

chrome.tabs.onUpdated.addListener((tabId, changeInfo, tab) => {
  if (changeInfo.status === 'complete') trackTab({ ...tab, id: tabId }, 'page_complete');
});

chrome.tabs.onActivated.addListener(({ tabId }) => {
  chrome.tabs.get(tabId, (tab) => {
    if (chrome.runtime.lastError) return;
    trackTab(tab, 'tab_activated');
  });
});

chrome.runtime.onMessage.addListener((message, sender, sendResponse) => {
  if (message?.type === 'trackCurrentTab') {
    chrome.tabs.query({ active: true, currentWindow: true }, async (tabs) => {
      const result = await trackTab(tabs[0], 'manual_test');
      sendResponse(result);
    });
    return true;
  }
  if (message?.type === 'refreshBadge') {
    setBadge().then(() => sendResponse({ ok: true }));
    return true;
  }
  if (message?.type === 'syncStatus') {
    getSettings()
      .then((settings) => reportTrackerStatus(settings, { force: true }))
      .then(() => sendResponse({ ok: true }));
    return true;
  }
  return false;
});

chrome.runtime.onInstalled.addListener(() => { setBadge(); ensurePolicyAlarm(); });
chrome.runtime.onStartup.addListener(() => { setBadge(); ensurePolicyAlarm(); });
if (chrome.alarms) chrome.alarms.onAlarm.addListener((alarm) => {
  if (alarm.name === 'snaillyPolicyRecheck') recheckActiveTabs();
});
chrome.storage.onChanged.addListener(async (changes, areaName) => {
  if (areaName !== 'local') return;
  await setBadge();
  const statusKeys = ['enabled', 'blockDangerous', 'token', 'childId', 'backendBase'];
  const shouldSyncStatus = statusKeys.some((key) => Object.prototype.hasOwnProperty.call(changes, key));
  if (shouldSyncStatus) reportTrackerStatus(await getSettings(), { force: true });
});
setBadge();
ensurePolicyAlarm();
