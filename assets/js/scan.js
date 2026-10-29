(function () {
  'use strict';
  var API = '/api/scan.php';
  var TOKEN_KEY = 'mzigo_point_token';
  var RECENT_KEY = 'mzigo_scan_recent';
  var TICK_ICON = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="26" height="26"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5"/></svg>';
  var QUEUE_KEY = 'mzigo_scan_queue';
  var LAST_ACTION_KEY = 'mzigo_scan_last_action';
  var token = '';
  var point = null;
  var pointStats = { today: 0, total: 0, last_scan_at: null };
  var parcelJourney = null;
  var autoAction = '';
  var lookupSeq = 0;
  var lookupTimer = 0;
  var selectedAction = '';
  var pendingKey = '';
  var qrStream = null;
  var qrRaf = 0;
  var qrOpening = false;
  var qrRequestSeq = 0;
  var queueFlushing = false;
  var torchOn = false;

  function $(sel) { return document.querySelector(sel); }
  function esc(value) {
    return String(value == null ? '' : value).replace(/[&<>"']/g, function (ch) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch];
    });
  }
  function toastError(sel, message) {
    var el = $(sel); if (!el) return;
    el.textContent = message; el.hidden = false;
  }
  function clearError(sel) { var el = $(sel); if (el) el.hidden = true; }
  function apiPost(body) {
    return fetch(API, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-Point-Token': token },
      body: JSON.stringify(body)
    }).then(parse);
  }
  function apiGet(params) {
    var query = '';
    if (params && params.tracking) query = '?tracking=' + encodeURIComponent(params.tracking);
    return fetch(API + query, { headers: { 'X-Point-Token': token }, cache: 'no-store' }).then(parse);
  }
  function parse(res) {
    return res.json().catch(function () { return { ok: false, error: { message: 'Unexpected response from the server.' } }; })
      .then(function (data) {
        if (!res.ok || data.ok === false) {
          throw new Error((data && data.error && data.error.message) || 'The request failed.');
        }
        return data;
      });
  }
  function setToken(value) {
    token = value || '';
    if (token) localStorage.setItem(TOKEN_KEY, token); else localStorage.removeItem(TOKEN_KEY);
  }
  function actionLabels() {
    return {
      dropoff: ['Drop off', 'Origin office'],
      handover: ['Hand over', 'To transporter'],
      arrival: ['Arrival', 'Destination office'],
      collection: ['Collect', 'Customer picked up']
    };
  }
  function relativeScanTime(value) {
    if (!value) return 'No scans';
    var then = new Date(String(value).replace(' ', 'T'));
    if (isNaN(then.getTime())) return '—';
    var mins = Math.max(0, Math.round((Date.now() - then.getTime()) / 60000));
    if (mins < 1) return 'Just now';
    if (mins < 60) return mins + 'm ago';
    var hours = Math.round(mins / 60);
    if (hours < 24) return hours + 'h ago';
    if (hours < 48) return 'Yesterday';
    return Math.round(hours / 24) + 'd ago';
  }
  function renderPointDash() {
    var mount = $('#pointDash');
    if (!mount) return;
    mount.innerHTML =
      '<div class="scan-point-dash__item"><strong>' + Number(pointStats.today || 0) + '</strong><span>Today</span></div>' +
      '<div class="scan-point-dash__item"><strong>' + Number(pointStats.total || 0) + '</strong><span>Total</span></div>' +
      '<div class="scan-point-dash__item"><strong>' + esc(relativeScanTime(pointStats.last_scan_at)) + '</strong><span>Last scan</span></div>';
  }

  function renderPoint() {
    $('#pointName').textContent = point.name;
    var meta = [point.code, point.location || point.partner_slug].filter(Boolean);
    $('#pointMeta').textContent = meta.join(' · ');
    $('#pointType').textContent = point.point_type;
    renderPointDash();
    var labels = actionLabels();
    var lastAction = localStorage.getItem(LAST_ACTION_KEY) || '';
    var configured = point.actions && point.actions.length
      ? point.actions.slice()
      : (point.allowed_actions || []).map(function (key) {
          var pair = labels[key] || [key, ''];
          return { key: key, label: pair[0], description: pair[1] };
        });
    configured.sort(function (a, b) {
      if (a.key === lastAction) return -1;
      if (b.key === lastAction) return 1;
      return 0;
    });
    $('#actionGrid').innerHTML = configured.map(function (a) {
      return '<button type="button" class="scan-action" data-scan-action="' + esc(a.key) + '"><strong>' + esc(a.label) + '</strong><span>' + esc(a.description || '') + '</span>' +
        (a.key === lastAction ? '<em>Recent</em>' : '') + '</button>';
    }).join('');
    selectedAction = '';
    Array.prototype.forEach.call(document.querySelectorAll('[data-scan-action]'), function (btn) {
      btn.addEventListener('click', function () {
        selectedAction = btn.getAttribute('data-scan-action');
        localStorage.setItem(LAST_ACTION_KEY, selectedAction);
        Array.prototype.forEach.call(document.querySelectorAll('[data-scan-action]'), function (b) { b.classList.remove('is-selected'); });
        btn.classList.add('is-selected');
        toggleCollection();
        syncActionState();
      });
    });
    toggleCollection();
    syncActionState();
    syncManualEntry();
    $('#manualActionBtn').hidden = false;
    $('#setup').hidden = true;
    $('#app').hidden = false;
  }
  function actionLabel(key) {
    if (point && point.actions && point.actions.length) {
      for (var i = 0; i < point.actions.length; i++) {
        if (point.actions[i].key === key) return point.actions[i].label || key;
      }
    }
    var labels = { dropoff: 'Drop off', handover: 'Hand over', arrival: 'Arrival', collection: 'Collect' };
    return labels[key] || key || '';
  }
  function selectActionButton() {
    Array.prototype.forEach.call(document.querySelectorAll('[data-scan-action]'), function (btn) {
      btn.classList.toggle('is-selected', btn.getAttribute('data-scan-action') === selectedAction);
    });
  }
  function renderJourneyRail(items, vertical) {
    var mount = $('#journeyRail');
    if (!mount) return;
    if (vertical) {
      mount.innerHTML = (items || []).map(function (item, index) {
        var mark = item.state === 'done' ? '✓' : (item.state === 'current' ? '●' : String(index + 1));
        return '<div class="scan-journey__vrow is-' + esc(item.state) + '">' +
          '<span class="scan-journey__dot">' + mark + '</span>' +
          '<div><strong>' + esc(item.label) + '</strong><small>' + esc(item.description || '') + '</small></div></div>';
      }).join('');
      return;
    }
    mount.innerHTML = (items || []).map(function (item, index) {
      var mark = item.state === 'done' ? '✓' : (item.state === 'current' ? '●' : String(index + 1));
      return '<div class="scan-journey__stop is-' + esc(item.state) + '">' +
        '<span class="scan-journey__dot">' + mark + '</span>' +
        '<strong>' + esc(item.label) + '</strong><small>' + esc(item.description || '') + '</small></div>' +
        (index < items.length - 1 ? '<span class="scan-journey__line' + (item.state === 'done' ? ' is-done' : '') + '"></span>' : '');
    }).join('');
  }
  function applyJourney(parcel) {
    parcelJourney = parcel;
    autoAction = parcel.next_action || '';
    selectedAction = autoAction;
    $('#journeyCard').hidden = false;
    $('#journeyTracking').textContent = parcel.tracking;
    $('#journeyCard').classList.toggle('scan-journey--vertical', !autoAction);
    renderJourneyRail(parcel.journey || [], !autoAction);
    var auto = $('#autoAction');
    if (autoAction) {
      auto.hidden = false;
      auto.innerHTML = '<span>Auto-selected action</span><strong>' + esc(actionLabel(autoAction)) + '</strong>' +
        '<small>Based on the parcel journey</small>';
      $('#actionGridWrap').hidden = true;
      $('#changeAction').hidden = false;
      $('#manualActionBtn').hidden = true;
      $('#scanHint').textContent = 'Journey loaded. The next valid action is selected.';
    } else {
      auto.hidden = false;
      auto.innerHTML = '<span>Parcel journey</span><strong>' +
        (parcel.blocked_reason || (parcel.status === 'COLLECTED_PAID' ? 'Journey complete' : 'No scan action available')) +
        '</strong><small>' + esc(parcel.tracking) + '</small>';
      $('#actionGridWrap').hidden = false;
      $('#changeAction').hidden = true;
      $('#manualActionBtn').hidden = false;
      $('#scanHint').textContent = parcel.blocked_reason || 'This parcel has no next scan action at this point.';
    }
    selectActionButton();
    syncActionState();
    toggleCollection();
  }
  function resetJourney() {
    lookupSeq++;
    parcelJourney = null;
    autoAction = '';
    selectedAction = '';
    $('#journeyCard').hidden = true;
    $('#autoAction').hidden = true;
    $('#changeAction').hidden = true;
    $('#actionGridWrap').hidden = true;
    $('#manualActionBtn').hidden = true;
    $('#journeyRail').innerHTML = '';
    $('#journeyCard').classList.remove('scan-journey--vertical');
    $('#journeyTracking').textContent = '';
    $('#scanHint').textContent = 'Scan the parcel label to load its journey and select the next action.';
    selectActionButton();
    syncActionState();
    toggleCollection();
  }
  function showManualActions() {
    $('#actionGridWrap').hidden = false;
    $('#manualActionBtn').hidden = true;
    $('#changeAction').hidden = true;
    $('#autoAction').hidden = true;
    $('#actionHint').textContent = 'Choose the scan action for this parcel.';
  }
  function lookupParcel(tracking) {
    var value = String(tracking || '').trim().toUpperCase();
    if (!value) return;
    if (parcelJourney && parcelJourney.tracking !== value) resetJourney();
    var seq = ++lookupSeq;
    $('#scanHint').textContent = 'Loading parcel journey…';
    apiGet({ tracking: value }).then(function (res) {
      if (seq !== lookupSeq) return;
      if (res.parcel) {
        applyJourney(res.parcel);
      } else {
        showManualActions();
        $('#scanHint').textContent = 'No parcel journey found. Choose the action manually.';
      }
    }).catch(function (err) {
      if (seq !== lookupSeq) return;
      showManualActions();
      $('#scanHint').textContent = isNetworkError(err)
        ? 'Journey lookup unavailable offline. Choose the action manually.'
        : err.message;
    });
  }

  function syncManualEntry() {
    var enabled = !point || point.manual_entry_enabled === undefined
      ? true
      : Number(point.manual_entry_enabled) === 1;
    var wrap = $('#manualEntryWrap');
    var input = $('#tracking');
    var value = $('#scanValue');
    var qr = $('#qrBtn');
    if (wrap) wrap.hidden = !enabled;
    if (input) input.required = enabled;
    if (value) value.hidden = enabled;
    if (qr) qr.classList.toggle('scan-btn--wide', !enabled);
  }
  function syncActionState() {
    var qrBtn = $('#qrBtn');
    if (qrBtn) {
      qrBtn.disabled = false;
      qrBtn.setAttribute('aria-disabled', 'false');
    }
    var submit = $('#submitScan');
    if (submit) submit.disabled = !selectedAction;
    var hint = $('#actionHint');
    if (hint) {
      hint.textContent = selectedAction ? 'The next action is selected.' : 'Choose a scan action.';
    }
  }
  function toggleCollection() {
    $('#collectWrap').hidden = selectedAction !== 'collection';
  }
  function recent() {
    try { return JSON.parse(localStorage.getItem(RECENT_KEY) || '[]'); } catch (e) { return []; }
  }
  function saveRecent(item) {
    var rows = recent();
    rows.unshift(item);
    localStorage.setItem(RECENT_KEY, JSON.stringify(rows.slice(0, 10)));
    renderRecent();
  }
  function renderRecent() {
    var rows = recent();
    var mount = $('#recentList');
    if (!rows.length) { mount.innerHTML = '<p class="scan-hint">No scans yet on this device.</p>'; return; }
    mount.innerHTML = rows.map(function (r) {
      return '<div class="scan-recent-row"><div><strong>' + esc(r.tracking) + '</strong><span> · ' + esc(r.action) + '</span></div>' +
        '<span>' + esc(r.label) + ' · ' + esc(r.at) + '</span></div>';
    }).join('');
  }
  function queueItems() {
    try { return JSON.parse(localStorage.getItem(QUEUE_KEY) || '[]'); } catch (e) { return []; }
  }
  function saveQueue(items) {
    localStorage.setItem(QUEUE_KEY, JSON.stringify(items.slice(0, 50)));
    renderQueueStatus();
  }
  function renderQueueStatus() {
    var el = $('#queueStatus');
    if (!el) return;
    var count = queueItems().length;
    el.textContent = count
      ? count + ' scan' + (count === 1 ? '' : 's') + ' waiting to sync. Keep the app open and reconnect to the internet.'
      : 'All scans synced.';
  }
  function isNetworkError(err) {
    return !navigator.onLine || err instanceof TypeError || /failed to fetch|networkerror|load failed|network request/i.test(String(err && err.message || ''));
  }
  function enqueueScan(payload) {
    var items = queueItems();
    items.push({ payload: payload, queued_at: new Date().toISOString() });
    saveQueue(items);
  }
  function flushQueue() {
    if (queueFlushing || !navigator.onLine || !token) return;
    var items = queueItems();
    if (!items.length) { renderQueueStatus(); return; }
    queueFlushing = true;
    var item = items[0];
    apiPost(item.payload).then(function (res) {
      var remaining = queueItems();
      remaining.shift();
      saveQueue(remaining);
      saveRecent({ tracking: res.tracking, action: item.payload.action, label: (res.label || 'Synced') + ' (queued)', at: new Date().toLocaleTimeString() });
      if (!res.idempotent) {
        pointStats.today = Number(pointStats.today || 0) + 1;
        pointStats.total = Number(pointStats.total || 0) + 1;
        pointStats.last_scan_at = new Date().toISOString();
        renderPointDash();
      }
      queueFlushing = false;
      if (remaining.length) flushQueue();
    }).catch(function () {
      queueFlushing = false;
      renderQueueStatus();
    });
  }
  function clearScanFields() {
    lookupSeq++;
    var tracking = $('#tracking');
    if (tracking) tracking.value = '';
    var scanValue = $('#scanValue');
    if (scanValue) scanValue.textContent = '';
    var collect = $('#collectCode');
    if (collect) collect.value = '';
    var note = $('#noteInput');
    if (note) note.value = '';
    var submit = $('#submitScan');
    if (submit) submit.disabled = false;
    if ($('#manualEntryWrap') && !$('#manualEntryWrap').hidden && tracking) tracking.focus();
  }
  function connect(value) {
    clearError('#setupError');
    setToken(value);
    return apiGet().then(function (res) {
      point = res.point;
      pointStats = res.point_stats || { today: 0, total: 0, last_scan_at: null };
      renderPoint();
      renderRecent();
    }).catch(function (err) {
      setToken('');
      $('#setup').hidden = false;
      $('#app').hidden = true;
      toastError('#setupError', err.message);
    });
  }
  function submitScan(ev) {
    ev.preventDefault();
    clearError('#scanError');
    var tracking = $('#tracking').value.trim().toUpperCase();
    if (!tracking) { toastError('#scanError', 'Enter a tracking number.'); return; }
    if (!selectedAction) { toastError('#scanError', 'Choose a scan action.'); return; }
    if (!$('#app').hidden) $('#submitScan').disabled = true;
    var pendingKeyForScan = (window.crypto && crypto.randomUUID) ? crypto.randomUUID() : ('scan-' + Date.now() + '-' + Math.random().toString(16).slice(2));
    var payload = {
      tracking: tracking, action: selectedAction, note: $('#noteInput').value.trim(),
      collect_code: $('#collectCode').value.trim(), idempotency_key: pendingKeyForScan
    };
    apiPost(payload).then(function (res) {
      pendingKey = '';
      var box = $('#result');
      box.hidden = false;
      box.className = 'scan-result scan-result--ok';
      box.setAttribute('role', 'status');
      box.innerHTML = '<div class="scan-success"><span class="scan-success__icon">' + TICK_ICON + '</span>' +
        '<div><strong>' + esc(res.label) + '</strong>' + esc(res.tracking) + ' · ' + esc(res.status) +
        (res.idempotent ? ' · already recorded' : '') + '</div></div>';
      saveRecent({ tracking: res.tracking, action: selectedAction, label: res.label, at: new Date().toLocaleTimeString() });
      if (!res.idempotent) {
        pointStats.today = Number(pointStats.today || 0) + 1;
        pointStats.total = Number(pointStats.total || 0) + 1;
        pointStats.last_scan_at = new Date().toISOString();
        renderPointDash();
      }
      clearScanFields();
      resetJourney();
    }).catch(function (err) {
      if (isNetworkError(err)) {
        enqueueScan(payload);
        var box = $('#result');
        box.hidden = false;
        box.className = 'scan-result scan-result--warn';
        box.innerHTML = '<strong>Saved offline</strong>' + esc(tracking) + ' will sync automatically when the connection returns.';
        saveRecent({ tracking: tracking, action: selectedAction, label: 'Queued - waiting to sync', at: new Date().toLocaleTimeString() });
        clearScanFields();
        resetJourney();
        renderQueueStatus();
        return;
      }
      toastError('#scanError', err.message);
      $('#submitScan').disabled = false;
    });
  }
  function setQrHint(text) {
    var hint = $('#qrHint');
    if (hint) hint.textContent = text;
  }
  function resetQrButton() {
    var btn = $('#qrBtn');
    if (btn) { btn.disabled = false; btn.setAttribute('aria-busy', 'false'); }
  }
  function closeQr() {
    qrRequestSeq++;
    clearTimeout(qrRaf);
    qrRaf = 0;
    if (qrStream) { qrStream.getTracks().forEach(function (t) { t.stop(); }); qrStream = null; }
    $('#qrOverlay').hidden = true;
    var preview = $('#qrPreview');
    if (preview) preview.srcObject = null;
    var torch = $('#qrTorch');
    if (torch) { torch.hidden = true; torch.onclick = null; torch.textContent = 'Turn torch on'; }
    torchOn = false;
    qrOpening = false;
    resetQrButton();
  }
  function openQr() {
    if (qrOpening || qrStream) return;
    var canNative = 'BarcodeDetector' in window;
    var canFallback = typeof window.jsQR === 'function';
    if (!navigator.mediaDevices || (!canNative && !canFallback)) {
      window.alert(point && Number(point.manual_entry_enabled) === 0
        ? 'This point is scan-only, but this browser does not support QR scanning. Use a supported Android device.'
        : 'This browser does not support QR scanning. Type the tracking number instead.');
      return;
    }
    qrOpening = true;
    var seq = ++qrRequestSeq;
    var btn = $('#qrBtn');
    if (btn) { btn.disabled = true; btn.setAttribute('aria-busy', 'true'); }
    $('#qrOverlay').hidden = false;
    setQrHint('Starting camera…');
    navigator.mediaDevices.getUserMedia({
      video: { facingMode: { ideal: 'environment' }, width: { ideal: 1280 }, height: { ideal: 720 } }
    }).then(function (stream) {
      if (seq !== qrRequestSeq) { stream.getTracks().forEach(function (t) { t.stop(); }); return; }
      qrStream = stream;
      var video = $('#qrPreview');
      video.srcObject = stream;
      var playResult = video.play();
      if (playResult && playResult.catch) playResult.catch(function () {});
      setQrHint('Point the camera at the parcel QR/Locker label.');
      var torch = $('#qrTorch');
      var track = stream.getVideoTracks()[0];
      try {
        var caps = track && track.getCapabilities ? track.getCapabilities() : null;
        if (torch && caps && caps.torch) {
          torch.hidden = false;
          torch.textContent = 'Turn torch on';
          torch.onclick = function () {
            torchOn = !torchOn;
            track.applyConstraints({ advanced: [{ torch: torchOn }] }).then(function () {
              torch.textContent = torchOn ? 'Turn torch off' : 'Turn torch on';
            }).catch(function () { torchOn = false; });
          };
        } else if (torch) {
          torch.hidden = true;
        }
      } catch (e) { if (torch) torch.hidden = true; }

      var detector = null;
      if (canNative) {
        try { detector = new window.BarcodeDetector({ formats: ['qr_code'] }); } catch (e) { detector = null; }
      }
      var canvas = document.createElement('canvas');
      var ctx = canvas.getContext('2d', { willReadFrequently: true });
      function detectFrame() {
        if (detector) return detector.detect(video);
        if (!window.jsQR || !video.videoWidth || !ctx) return Promise.resolve([]);
        var scale = Math.min(1, 640 / video.videoWidth);
        canvas.width = Math.max(1, Math.round(video.videoWidth * scale));
        canvas.height = Math.max(1, Math.round(video.videoHeight * scale));
        ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
        var image = ctx.getImageData(0, 0, canvas.width, canvas.height);
        var code = window.jsQR(image.data, image.width, image.height, { inversionAttempts: 'dontInvert' });
        return Promise.resolve(code ? [{ rawValue: code.data }] : []);
      }
      function scheduleNext(delay) {
        if (!qrStream || seq !== qrRequestSeq) return;
        qrRaf = setTimeout(tick, delay || 180);
      }
      function tick() {
        if (!qrStream || seq !== qrRequestSeq) return;
        detectFrame().then(function (codes) {
          if (!qrStream || seq !== qrRequestSeq) return;
          var raw = codes && codes.length ? String(codes[0].rawValue || '') : '';
          var match = raw.match(/(?:TRK|LK|ERD)-[A-Z0-9-]{3,}/i);
          if (match) {
            var scanned = match[0].toUpperCase();
            $('#tracking').value = scanned;
            var scanValue = $('#scanValue');
            if (scanValue) scanValue.textContent = 'Scanned: ' + scanned;
            if (navigator.vibrate) navigator.vibrate(80);
            closeQr();
            lookupParcel(scanned);
            if (!$('#manualEntryWrap').hidden) $('#tracking').focus();
          } else {
            if (raw !== '') setQrHint('Not a Mzigo label — keep the camera on a valid QR/Locker label.');
            scheduleNext(180);
          }
        }).catch(function () {
          if (!qrStream || seq !== qrRequestSeq) return;
          setQrHint('Camera scanning… hold the label steady.');
          scheduleNext(350);
        });
      }
      scheduleNext(0);
    }).catch(function () {
      closeQr();
      window.alert('Camera permission was denied. Type the tracking number instead.');
    }).finally(function () {
      qrOpening = false;
      resetQrButton();
      flushQueue();
    });
  }
  function updateNet() {
    var el = $('#netState');
    var online = navigator.onLine;
    el.textContent = online ? 'Online' : 'Offline';
    el.className = 'scan-pill ' + (online ? 'scan-pill--ok' : 'scan-pill--warn');
  }
  document.addEventListener('DOMContentLoaded', function () {
    updateNet();
    window.addEventListener('online', function () { updateNet(); flushQueue(); });
    window.addEventListener('offline', updateNet);
    renderRecent();
    renderQueueStatus();
    $('#tokenForm').addEventListener('submit', function (ev) {
      ev.preventDefault();
      var input = $('#tokenInput');
      var btn = ev.target.querySelector('button');
      btn.disabled = true;
      connect(input.value.trim()).finally(function () { btn.disabled = false; });
    });
    $('#scanForm').addEventListener('submit', submitScan);
    $('#qrBtn').addEventListener('click', openQr);
    $('#qrClose').addEventListener('click', closeQr);
    $('#manualActionBtn').addEventListener('click', showManualActions);
    $('#changeAction').addEventListener('click', showManualActions);
    $('#tracking').addEventListener('input', function () {
      clearTimeout(lookupTimer);
      var value = $('#tracking').value.trim();
      if (value.length < 4) { if (!value) resetJourney(); return; }
      lookupTimer = setTimeout(function () { lookupParcel(value); }, 650);
    });
    $('#tracking').addEventListener('keydown', function (ev) {
      if (ev.key === 'Enter') { ev.preventDefault(); lookupParcel($('#tracking').value); }
    });
    $('#settingsBtn').addEventListener('click', function () { renderRecent(); renderQueueStatus(); flushQueue(); $('#settingsOverlay').hidden = false; });
    $('#settingsClose').addEventListener('click', function () { $('#settingsOverlay').hidden = true; });
    $('#settingsOverlay').addEventListener('click', function (ev) {
      if (ev.target === $('#settingsOverlay')) $('#settingsOverlay').hidden = true;
    });
    $('#clearRecent').addEventListener('click', function () { localStorage.removeItem(RECENT_KEY); renderRecent(); });
    var recentNav = document.querySelector('[data-scan-nav="recent"]');
    if (recentNav) recentNav.addEventListener('click', function (ev) {
      ev.preventDefault();
      renderRecent();
      renderQueueStatus();
      flushQueue();
      $('#settingsOverlay').hidden = false;
    });
    $('#changeToken').addEventListener('click', function () {
      if (!window.confirm('Disconnect this point token from this phone?')) return;
      setToken(''); point = null; pendingKey = '';
      $('#settingsOverlay').hidden = true;
      $('#app').hidden = true; $('#setup').hidden = false;
      $('#tokenInput').value = '';
      $('#setupError').hidden = true;
    });
    var saved = localStorage.getItem(TOKEN_KEY);
    if (saved) connect(saved).then(function () { flushQueue(); }).catch(function () {});
    if ('serviceWorker' in navigator) navigator.serviceWorker.register('/scan-sw.js').catch(function () {});
    var installPrompt = null;
    window.addEventListener('beforeinstallprompt', function (ev) {
      ev.preventDefault(); installPrompt = ev; $('#installBtn').hidden = false;
    });
    $('#installBtn').addEventListener('click', function () {
      if (!installPrompt) return;
      installPrompt.prompt(); installPrompt = null; $('#installBtn').hidden = true;
    });
  });
})();
