(function () {
  'use strict';
  var API = '/api/scan.php';
  var TOKEN_KEY = 'mzigo_point_token';
  var RECENT_KEY = 'mzigo_scan_recent';
  var token = '';
  var point = null;
  var selectedAction = '';
  var pendingKey = '';
  var qrStream = null;
  var qrRaf = 0;
  var qrOpening = false;

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
  function apiGet() {
    return fetch(API, { headers: { 'X-Point-Token': token }, cache: 'no-store' }).then(parse);
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
  function renderPoint() {
    $('#pointName').textContent = point.name;
    $('#pointMeta').textContent = point.code + ' · ' + point.partner_slug + ' · ' + (point.location || 'No location set');
    $('#pointType').textContent = point.point_type;
    var labels = actionLabels();
    var actions = point.allowed_actions || [];
    $('#actionGrid').innerHTML = actions.map(function (a) {
      var pair = labels[a] || [a, ''];
      return '<button type="button" class="scan-action" data-scan-action="' + esc(a) + '"><strong>' + esc(pair[0]) + '</strong><span>' + esc(pair[1]) + '</span></button>';
    }).join('');
    selectedAction = actions.length === 1 ? actions[0] : '';
    Array.prototype.forEach.call(document.querySelectorAll('[data-scan-action]'), function (btn) {
      if (btn.getAttribute('data-scan-action') === selectedAction) btn.classList.add('is-selected');
      btn.addEventListener('click', function () {
        selectedAction = btn.getAttribute('data-scan-action');
        Array.prototype.forEach.call(document.querySelectorAll('[data-scan-action]'), function (b) { b.classList.remove('is-selected'); });
        btn.classList.add('is-selected');
        toggleCollection();
      });
    });
    toggleCollection();
    $('#setup').hidden = true;
    $('#app').hidden = false;
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
  function connect(value) {
    clearError('#setupError');
    setToken(value);
    return apiGet().then(function (res) {
      point = res.point;
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
    if (!pendingKey) pendingKey = (window.crypto && crypto.randomUUID) ? crypto.randomUUID() : ('scan-' + Date.now() + '-' + Math.random().toString(16).slice(2));
    apiPost({
      tracking: tracking, action: selectedAction, note: $('#noteInput').value.trim(),
      collect_code: $('#collectCode').value.trim(), idempotency_key: pendingKey
    }).then(function (res) {
      pendingKey = '';
      var box = $('#result');
      box.hidden = false;
      box.className = 'scan-result scan-result--ok';
      box.innerHTML = '<strong>' + esc(res.label) + '</strong>' +
        res.tracking + ' · ' + esc(res.status) + (res.idempotent ? ' · already recorded' : '');
      saveRecent({ tracking: res.tracking, action: selectedAction, label: res.label, at: new Date().toLocaleTimeString() });
      $('#tracking').value = '';
      $('#collectCode').value = '';
      $('#noteInput').value = '';
      $('#submitScan').disabled = false;
      $('#tracking').focus();
    }).catch(function (err) {
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
  function openQr() {
    if (qrOpening || qrStream) return;
    if (!('BarcodeDetector' in window) || !navigator.mediaDevices) {
      window.alert('This browser does not support QR scanning. Type the tracking number instead.');
      return;
    }
    qrOpening = true;
    var btn = $('#qrBtn');
    if (btn) { btn.disabled = true; btn.setAttribute('aria-busy', 'true'); }
    $('#qrOverlay').hidden = false;
    setQrHint('Starting camera…');
    navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } }).then(function (stream) {
      qrStream = stream;
      var video = $('#qrPreview');
      video.srcObject = stream;
      var playResult = video.play();
      if (playResult && playResult.catch) playResult.catch(function () {});
      setQrHint('Point the camera at the parcel QR/Locker label.');
      var detector = new window.BarcodeDetector({ formats: ['qr_code', 'code_128', 'code_39'] });
      function tick() {
        if (!qrStream) return;
        detector.detect(video).then(function (codes) {
          if (codes && codes.length) {
            var raw = codes[0].rawValue || '';
            var match = raw.match(/TRK-[A-Z0-9]+/i);
            $('#tracking').value = (match ? match[0] : raw).toUpperCase();
            closeQr();
          } else {
            qrRaf = requestAnimationFrame(tick);
          }
        }).catch(function () { closeQr(); });
      }
      qrRaf = requestAnimationFrame(tick);
    }).catch(function () {
      closeQr();
      window.alert('Camera permission was denied. Type the tracking number instead.');
    }).finally(function () {
      qrOpening = false;
      resetQrButton();
    });
  }
  function closeQr() {
    cancelAnimationFrame(qrRaf);
    qrRaf = 0;
    if (qrStream) { qrStream.getTracks().forEach(function (t) { t.stop(); }); qrStream = null; }
    $('#qrOverlay').hidden = true;
    var preview = $('#qrPreview');
    if (preview) preview.srcObject = null;
    qrOpening = false;
    resetQrButton();
  }
  function updateNet() {
    var el = $('#netState');
    var online = navigator.onLine;
    el.textContent = online ? 'Online' : 'Offline';
    el.className = 'scan-pill ' + (online ? 'scan-pill--ok' : 'scan-pill--warn');
  }
  document.addEventListener('DOMContentLoaded', function () {
    updateNet();
    window.addEventListener('online', updateNet);
    window.addEventListener('offline', updateNet);
    renderRecent();
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
    $('#clearRecent').addEventListener('click', function () { localStorage.removeItem(RECENT_KEY); renderRecent(); });
    $('#changeToken').addEventListener('click', function () {
      if (!window.confirm('Disconnect this point token from this phone?')) return;
      setToken(''); point = null; pendingKey = '';
      $('#app').hidden = true; $('#setup').hidden = false;
      $('#tokenInput').value = '';
      $('#setupError').hidden = true;
    });
    var saved = localStorage.getItem(TOKEN_KEY);
    if (saved) connect(saved).catch(function () {});
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
