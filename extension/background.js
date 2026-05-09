const DEFAULTS = {
  backendBase: 'http://127.0.0.1:8000',
  token: '',
  childId: '',
  enabled: true,
  blockDangerous: false,
  ignoreLocalhost: true,
  lastTracked: null,
  lastError: ''
};

const recentByTab = new Map();

function normalizeBase(base) {
  return String(base || DEFAULTS.backendBase).replace(/\/+$/, '');
}

async function getSettings() {
  return new Promise((resolve) => {
    chrome.storage.local.get(DEFAULTS, (items) => {
      resolve({ ...DEFAULTS, ...items, backendBase: normalizeBase(items.backendBase) });
    });
  });
}

async function reportTrackerStatus(settings) {
  if (!settings?.token || !settings?.childId) return;
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
  if (now - last < 30000) return true;
  recentByTab.set(key, now);

  // Keep the map from growing forever.
  for (const [itemKey, itemTime] of recentByTab.entries()) {
    if (now - itemTime > 120000) recentByTab.delete(itemKey);
  }
  return false;
}

async function trackTab(tab, reason = 'tab_event') {
  const settings = await getSettings();
  await setBadge();

  if (!settings.enabled) return { ok: false, skipped: true, message: 'Tracking disabled.' };
  if (!settings.token || !settings.childId) return { ok: false, skipped: true, message: 'Extension is not configured.' };

  const url = tab?.url || '';
  if (!isTrackableUrl(url, settings)) return { ok: false, skipped: true, message: 'URL ignored.' };
  // Kalau mode blocking aktif, jangan skip duplicate.
  // Alasannya: parent bisa saja baru saja menekan lock pada URL yang sama,
  // sehingga extension perlu tanya backend lagi agar bisa redirect ke blocked page.
  if (isRecentDuplicate(tab.id || 0, url)) {
    return { ok: true, skipped: true, message: 'Duplicate ignored.' };
  }

  try {
    const response = await fetch(`${settings.backendBase}/api/snailly/track`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-Snailly-Authorization': `Bearer ${settings.token}`
      },
      body: JSON.stringify({
        childId: settings.childId,
        url,
        title: tab?.title || '',
        source: `chrome_extension:${reason}`
      })
    });

    const text = await response.text();
    let json = {};
    try { json = text ? JSON.parse(text) : {}; }
    catch (_) { json = { ok: false, message: text || 'Invalid response from backend.' }; }

    if (!response.ok || json.ok === false) {
      throw new Error(json.message || `Backend error ${response.status}`);
    }

    await chrome.storage.local.set({
      lastTracked: {
        url,
        title: tab?.title || '',
        at: new Date().toISOString(),
        label: json.label || (json.action === 'warn' ? 'peringatan' : (json.blocked ? 'bahaya' : 'aman')),
        action: json.action || (json.blocked ? 'block' : 'allow'),
        blocked: json.blocked === true,
        duplicate: Boolean(json.duplicate),
        category: json.category || '',
        reason: json.reason || ''
      },
      lastError: ''
    });

    if (settings.blockDangerous && json.blocked === true && tab?.id) {
      const blockUrl = `${settings.backendBase}/snailly/blocked?url=${encodeURIComponent(url)}&childId=${encodeURIComponent(settings.childId)}&token=${encodeURIComponent(settings.token)}&category=${encodeURIComponent(json.category || '')}&reason=${encodeURIComponent(json.reason || '')}`;
      await chrome.tabs.update(tab.id, { url: blockUrl });
    }

    return json;
  } catch (error) {
    await chrome.storage.local.set({ lastError: error.message || String(error) });
    return { ok: false, message: error.message || String(error) };
  }
}

chrome.tabs.onUpdated.addListener((tabId, changeInfo, tab) => {
  if (changeInfo.status === 'complete') {
    trackTab({ ...tab, id: tabId }, 'page_complete');
  }
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
    getSettings().then((settings) => reportTrackerStatus(settings)).then(() => sendResponse({ ok: true }));
    return true;
  }
  return false;
});

chrome.runtime.onInstalled.addListener(() => { setBadge(); ensurePolicyAlarm(); });
chrome.runtime.onStartup.addListener(() => { setBadge(); ensurePolicyAlarm(); });
if (chrome.alarms) chrome.alarms.onAlarm.addListener((alarm) => {
  if (alarm.name === 'snaillyPolicyRecheck') recheckActiveTabs();
});
chrome.storage.onChanged.addListener(async () => {
  await setBadge();
  reportTrackerStatus(await getSettings());
});
setBadge();
ensurePolicyAlarm();
